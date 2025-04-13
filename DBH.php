 <?php
declare(strict_types=1);

interface Cache
{
    public function set(string $key, mixed $value, int $ttl = 3600): void;
    public function get(string $key): mixed;
    public function delete(string $key): void;
}

class MemcachedCache implements Cache
{
    private Memcached $client;

    public function __construct()
    {
        $this->client = new Memcached();
        $this->client->addServer('localhost', 11211);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->client->set($key, $value, $ttl);
    }

    public function get(string $key): mixed
    {
        return $this->client->get($key);
    }

    public function delete(string $key): void
    {
        $this->client->delete($key);
    }
}

class DB
{
    protected static ?PDO $instance = null;
    protected static ?Cache $cache = null;
    protected static int $connectionTimeout = 5;

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() {
        throw new \RuntimeException("Cannot unserialize singleton");
    }

    // Настройка соединения
    public static function setConnectionTimeout(int $seconds): void
    {
        self::$connectionTimeout = $seconds;
    }

    public static function setInstance(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, self::$connectionTimeout);
        self::$instance = $pdo;
    }

    public static function instance(): PDO
    {
        if (!self::$instance) {
            throw new \RuntimeException("PDO instance not configured");
        }
        return self::$instance;
    }

    // Кэширование
    public static function setCache(Cache $cache): void
    {
        self::$cache = $cache;
    }

    // Основные методы
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        if (empty(trim($sql))) {
            throw new \InvalidArgumentException("SQL query cannot be empty");
        }

        try {
            $stmt = self::instance()->prepare($sql);
            $stmt->execute(self::normalizeParams($params));
            
            if (self::$cache && stripos($sql, 'SELECT') === 0) {
                $cacheKey = self::generateCacheKey($sql, $params);
                self::$cache->set($cacheKey, $stmt->fetchAll());
            }
            
            return $stmt;
        } catch (\PDOException $e) {
            self::logError($e, $sql, $params);
            throw $e;
        }
    }

    // CRUD
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
        $cacheKey = self::generateCacheKey(
            "SELECT $columns FROM $table WHERE " . self::buildWhere($conditions) . $order . $limit . $offset,
            $conditions
        );
        
        if (self::$cache) {
            $result = self::$cache->get($cacheKey);
            if ($result !== null) {
                return $result;
            }
        }

        $result = self::query(
            "SELECT $columns FROM $table WHERE " . self::buildWhere($conditions) . $order . $limit . $offset,
            $conditions
        )->fetchAll();

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

    // Транзакции
    public static function transaction(callable $callback, string $isolationLevel = 'SERIALIZABLE')
    {
        $pdo = self::instance();
        
        if ($pdo->inTransaction()) {
            return $callback();
        }

        try {
            $pdo->beginTransaction();
            $pdo->exec("SET TRANSACTION ISOLATION LEVEL $isolationLevel");
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException("Transaction failed: " . $e->getMessage(), 0, $e);
        }
    }

    // Bulk операции
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

    // Агрегации
    public static function count(string $table, array $conditions = []): int
    {
        return (int)self::first($table, $conditions, 'COUNT(*) as count')['count'];
    }

    public static function sum(string $table, string $column, array $conditions = []): float
    {
        return (float)self::first($table, $conditions, "SUM($column) as sum")['sum'];
    }

    // Вспомогательные методы
    private static function buildWhere(array $conditions): string
    {
        $parts = [];
        foreach ($conditions as $key => $value) {
            $operator = '=';
            if (str_contains($key, ' ')) {
                [$field, $operator] = explode(' ', $key, 2);
                $field = self::sanitizeIdentifier($field);
                $parts[] = "$field $operator ?";
            } elseif (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $parts[] = self::sanitizeIdentifier($key) . " IN ($placeholders)";
            } else {
                $parts[] = self::sanitizeIdentifier($key) . ' = ?';
            }
        }
        return implode(' AND ', $parts);
    }

    private static function sanitizeIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: $identifier");
        }
        return "`$identifier`";
    }

    private static function normalizeParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            if ($value instanceof \DateTime) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif (is_array($value)) {
                continue; // Обрабатывается в buildWhere
            }
            $normalized[is_string($key) ? ":$key" : $key] = $value;
        }
        return $normalized;
    }

    private static function logError(\PDOException $e, string $sql = null, array $params = null): void
    {
        $message = "[DB Error] " . $e->getMessage();
        if ($sql) $message .= "\nSQL: " . $sql;
        if ($params) $message .= "\nParams: " . json_encode($params);
        
        error_log($message);
        
        if (self::$cache && $sql) {
            self::$cache->delete(self::generateCacheKey($sql, $params));
        }
        
        if (defined('DB_DEBUG') && DB_DEBUG) {
            throw new \RuntimeException($message);
        }
    }

    private static function validateData(array $data): void
    {
        if (empty($data)) {
            throw new \InvalidArgumentException("Data cannot be empty");
        }
    }

    private static function validateConditions(array $conditions): void
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException("Conditions cannot be empty for this operation");
        }
    }

    private static function generateCacheKey(string $sql, array $params): string
    {
        return md5(serialize([
            $sql,
            array_values($params),
            self::instance()->getAttribute(PDO::ATTR_SERVER_VERSION)
        ]));
    }

    public static function clearCache(string $sql, array $params = []): void
    {
        if (self::$cache) {
            self::$cache->delete(self::generateCacheKey($sql, $params));
        }
    }
}
