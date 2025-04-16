Дополненная полная реализация класса DBHmariaDB:

```php
<?php
declare(strict_types=1);

interface Cache
{
    public function set(string $key, mixed $value, int $ttl = 3600): void;
    public function get(string $key): mixed;
    public function delete(string $key): void;
}

class MemcachedCache implements Cache { /* Реализация из предыдущего примера */ }

class DBHmariaDB
{
    protected static ?PDO $instance = null;
    protected static ?Cache $cache = null;
    protected static int $connectionTimeout = 5;

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() {
        throw new \RuntimeException("Cannot unserialize singleton");
    }

    // Полная реализация методов подключения
    public static function configure(
        string $host,
        string $dbname,
        string $user,
        string $pass,
        string $charset = 'utf8mb4'
    ): void {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => self::$connectionTimeout,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES'",
            PDO::MYSQL_ATTR_FOUND_ROWS => true
        ];
        
        self::$instance = new PDO($dsn, $user, $pass, $options);
    }

    public static function setInstance(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, self::$connectionTimeout);
        self::$instance = $pdo;
    }

    public static function setConnectionTimeout(int $seconds): void
    {
        self::$connectionTimeout = $seconds;
        if (self::$instance) {
            self::$instance->setAttribute(PDO::ATTR_TIMEOUT, $seconds);
        }
    }

    public static function instance(): PDO
    {
        if (!self::$instance) {
            throw new \RuntimeException("MariaDB connection not configured");
        }
        return self::$instance;
    }

    // Полная реализация кэширования
    public static function setCache(Cache $cache): void
    {
        self::$cache = $cache;
    }

    public static function clearCache(string $sql, array $params = []): void
    {
        if (self::$cache) {
            $cacheKey = self::generateCacheKey($sql, $params);
            self::$cache->delete($cacheKey);
        }
    }

    // Полные CRUD методы
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $sql = self::optimizeQuery($sql);
        
        if (empty(trim($sql))) {
            throw new \InvalidArgumentException("SQL query cannot be empty");
        }

        try {
            $stmt = self::instance()->prepare($sql);
            $normalizedParams = self::normalizeParams($params);
            $stmt->execute($normalizedParams);
            
            if (self::$cache && stripos($sql, 'SELECT') === 0) {
                $cacheKey = self::generateCacheKey($sql, $params);
                self::$cache->set($cacheKey, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            
            return $stmt;
        } catch (\PDOException $e) {
            self::logError($e, $sql, $params);
            throw $e;
        }
    }

    public static function insert(string $table, array $data): string
    {
        self::validateData($data);
        
        $table = self::sanitizeIdentifier($table);
        $columns = implode(', ', array_map([self::class, 'sanitizeIdentifier'], array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        self::query("INSERT INTO $table ($columns) VALUES ($placeholders)", array_values($data));
        return self::instance()->lastInsertId();
    }

    public static function select(
        string $table,
        array $conditions = [],
        string $columns = '*',
        string $order = '',
        int $limit = 0,
        int $offset = 0
    ): array {
        $table = self::sanitizeIdentifier($table);
        $sql = "SELECT $columns FROM $table";
        $where = '';
        $params = [];
        
        if (!empty($conditions)) {
            $where = ' WHERE ' . self::buildWhere($conditions);
            $params = $conditions;
        }
        
        $sql .= $where;
        
        if (!empty($order)) {
            $sql .= " ORDER BY $order";
        }
        
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        if ($offset > 0) {
            $sql .= " OFFSET " . (int)$offset;
        }

        $cacheKey = self::generateCacheKey($sql, $params);
        
        if (self::$cache) {
            $result = self::$cache->get($cacheKey);
            if ($result !== null) {
                return $result;
            }
        }

        $stmt = self::query($sql, $params);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (self::$cache) {
            self::$cache->set($cacheKey, $result);
        }
        
        return $result;
    }

    public static function first(string $table, array $conditions = [], string $columns = '*'): ?array
    {
        $result = self::select($table, $conditions, $columns, limit: 1);
        return $result[0] ?? null;
    }

    public static function update(string $table, array $data, array $conditions): int
    {
        self::validateData($data);
        self::validateConditions($conditions);

        $table = self::sanitizeIdentifier($table);
        $set = implode(', ', array_map(
            fn($k) => self::sanitizeIdentifier($k) . ' = ?',
            array_keys($data)
        ));
        
        $where = self::buildWhere($conditions);
        $params = array_merge(array_values($data), array_values($conditions));
        
        return self::query("UPDATE $table SET $set WHERE $where", $params)
            ->rowCount();
    }

    public static function delete(string $table, array $conditions): int
    {
        self::validateConditions($conditions);

        $table = self::sanitizeIdentifier($table);
        $where = self::buildWhere($conditions);
        
        return self::query("DELETE FROM $table WHERE $where", $conditions)
            ->rowCount();
    }

    // Полная реализация транзакций
    public static function transaction(callable $callback, string $isolationLevel = 'REPEATABLE READ')
    {
        $pdo = self::instance();
        
        if ($pdo->inTransaction()) {
            return $callback();
        }

        try {
            $pdo->exec("SET TRANSACTION ISOLATION LEVEL $isolationLevel");
            $pdo->beginTransaction();
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException("Transaction failed: " . $e->getMessage(), 0, $e);
        }
    }

    // Полная реализация bulk операций
    public static function bulkInsert(string $table, array $rows): void
    {
        if (empty($rows)) return;
        
        $columns = array_keys($rows[0]);
        $table = self::sanitizeIdentifier($table);
        $columnsList = implode(', ', array_map([self::class, 'sanitizeIdentifier'], $columns));
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $values = implode(', ', array_fill(0, count($rows), $placeholders));

        $params = [];
        foreach ($rows as $row) {
            $params = array_merge($params, array_values($row));
        }

        self::query("INSERT INTO $table ($columnsList) VALUES $values", $params);
    }

    // Полные агрегатные методы
    public static function count(string $table, array $conditions = []): int
    {
        return (int)(self::first($table, $conditions, 'COUNT(*) as count')['count'] ?? 0);
    }

    public static function sum(string $table, string $column, array $conditions = []): float
    {
        return (float)(self::first($table, $conditions, "SUM($column) as sum")['sum'] ?? 0.0);
    }

    // Специфичные методы MariaDB
    public static function getFoundRows(): int
    {
        return (int)self::query("SELECT FOUND_ROWS()")->fetchColumn();
    }

    public static function setSessionVariable(string $
