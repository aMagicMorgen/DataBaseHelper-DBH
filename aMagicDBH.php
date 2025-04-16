<?php
declare(strict_types=1);

interface Cache {
    public function set(string $key, mixed $value, int $ttl = 3600): void;
    public function get(string $key): mixed;
    public function delete(string $key): void;
}

abstract class DatabaseDriver {
    abstract public function getQuoteChar(): string;
    abstract public function autoIncrement(): string;
    abstract public function initCommands(): array;
    abstract public function formatDate(string $func): string;
    abstract public function handleLimit(int $limit, int $offset): string;
    
    public function isTransactional(): bool { return true; }
    public function supportsForeignKeys(): bool { return true; }
    
    public function convertBoolean(bool $value): int {
        return $value ? 1 : 0;
    }
}

class MySQLDriver extends DatabaseDriver {
    public function getQuoteChar(): string { return '`'; }
    public function autoIncrement(): string { return 'AUTO_INCREMENT'; }
    public function initCommands(): array { return ['SET sql_mode = "STRICT_ALL_TABLES"']; }
    public function formatDate(string $func): string {
        return match(strtoupper($func)) {
            'NOW' => 'NOW()',
            'DATE' => 'CURDATE()',
            'TIME' => 'CURTIME()',
            default => "DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')"
        };
    }
    public function handleLimit(int $limit, int $offset): string {
        return "LIMIT $limit OFFSET $offset";
    }
}

class SQLiteDriver extends DatabaseDriver {
    public function getQuoteChar(): string { return '"'; }
    public function autoIncrement(): string { return 'AUTOINCREMENT'; }
    public function initCommands(): array { return ['PRAGMA journal_mode = WAL']; }
    public function formatDate(string $func): string {
        $format = match(strtoupper($func)) {
            'NOW' => '%Y-%m-%d %H:%M:%S',
            'DATE' => '%Y-%m-%d',
            'TIME' => '%H:%M:%S',
            default => '%Y-%m-%d %H:%M:%S'
        };
        return "strftime('$format', 'now')";
    }
    public function handleLimit(int $limit, int $offset): string {
        return "LIMIT $limit OFFSET $offset";
    }
    public function isTransactional(): bool { return false; }
    public function supportsForeignKeys(): bool { return false; }
}

class aMagicDBH {
    private static ?PDO $instance = null;
    private static ?DatabaseDriver $driver = null;
    private static ?Cache $cache = null;
    private static array $config = [
        'timeout' => 5,
        'debug' => false,
        'enable_cache' => true
    ];

    // ================ CORE METHODS ================ //

    public static function connect(array $config): void {
        self::validateConfig($config);
        
        $dsn = self::buildDsn($config);
        $options = self::buildOptions($config);
        
        self::$instance = new PDO(
            $dsn,
            $config['user'] ?? null,
            $config['pass'] ?? null,
            $options
        );

        self::detectDriver();
        self::runInitCommands();
        self::$config = array_merge(self::$config, $config);
    }

    public static function setCache(Cache $cache): void {
        self::$cache = $cache;
    }

    // ================ CRUD OPERATIONS ================ //

    public static function insert(string $table, array $data): string {
        self::validateTableName($table);
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = sprintf("INSERT INTO %s (%s) VALUES (%s)",
            self::quoteIdentifier($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            $placeholders
        );

        self::execute($sql, array_values($data));
        return self::$instance->lastInsertId();
    }

    public static function select(
        string $table,
        array $conditions = [],
        string $columns = '*',
        string $order = '',
        int $limit = 0,
        int $offset = 0
    ): array {
        self::validateTableName($table);
        [$where, $params] = self::buildWhere($conditions);
        $sql = sprintf("SELECT %s FROM %s", $columns, self::quoteIdentifier($table));
        
        if (!empty($where)) $sql .= " WHERE $where";
        if (!empty($order)) $sql .= " ORDER BY $order";
        if ($limit > 0) $sql .= " " . self::$driver->handleLimit($limit, $offset);

        return self::cachedQuery($sql, $params);
    }

    public static function update(string $table, array $data, array $conditions): int {
        self::validateTableName($table);
        [$set, $setParams] = self::buildSet($data);
        [$where, $whereParams] = self::buildWhere($conditions);
        
        $sql = sprintf("UPDATE %s SET %s WHERE %s",
            self::quoteIdentifier($table),
            $set,
            $where
        );

        $stmt = self::execute($sql, array_merge($setParams, $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(string $table, array $conditions): int {
        self::validateTableName($table);
        [$where, $params] = self::buildWhere($conditions);
        
        $sql = sprintf("DELETE FROM %s WHERE %s",
            self::quoteIdentifier($table),
            $where
        );

        $stmt = self::execute($sql, $params);
        return $stmt->rowCount();
    }

    // ================ TRANSACTIONS ================ //

    public static function transaction(callable $callback): mixed {
        if (!self::$driver->isTransactional()) {
            throw new RuntimeException("Transactions not supported for current driver");
        }

        try {
            self::$instance->beginTransaction();
            $result = $callback();
            self::$instance->commit();
            return $result;
        } catch (Throwable $e) {
            self::$instance->rollBack();
            throw new RuntimeException("Transaction failed: " . $e->getMessage(), 0, $e);
        }
    }

    // ================ UTILITY METHODS ================ //

    public static function first(string $table, array $conditions = [], string $columns = '*'): ?array {
        $result = self::select($table, $conditions, $columns, limit: 1);
        return $result[0] ?? null;
    }

    public static function count(string $table, array $conditions = []): int {
        $result = self::first($table, $conditions, 'COUNT(*) as count');
        return (int)($result['count'] ?? 0);
    }

    public static function sum(string $table, string $column, array $conditions = []): float {
        $result = self::first($table, $conditions, "SUM($column) as sum");
        return (float)($result['sum'] ?? 0.0);
    }

    public static function optimize(): void {
        match (get_class(self::$driver)) {
            SQLiteDriver::class => self::execute('VACUUM'),
            MySQLDriver::class => self::execute('OPTIMIZE TABLE'),
            default => null
        };
    }

    // ================ INTERNAL METHODS ================ //

    private static function buildDsn(array $config): string {
        if (isset($config['sqlite'])) {
            return "sqlite:" . $config['sqlite'];
        }
        
        $charset = $config['charset'] ?? 'utf8mb4';
        return sprintf("mysql:host=%s;dbname=%s;charset=%s",
            $config['host'],
            $config['dbname'],
            $charset
        );
    }

    private static function buildOptions(array $config): array {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $config['timeout'] ?? 5,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
    }

    private static function detectDriver(): void {
        $driverName = self::$instance->getAttribute(PDO::ATTR_DRIVER_NAME);
        self::$driver = match ($driverName) {
            'mysql' => new MySQLDriver(),
            'sqlite' => new SQLiteDriver(),
            default => throw new RuntimeException("Unsupported driver: $driverName")
        };
    }

    private static function runInitCommands(): void {
        foreach (self::$driver->initCommands() as $command) {
            self::$instance->exec($command);
        }
    }

    private static function buildWhere(array $conditions): array {
        $clauses = [];
        $params = [];
        
        foreach ($conditions as $key => $value) {
            $operator = '=';
            $field = $key;
            
            if (str_contains($key, ' ')) {
                [$field, $operator] = explode(' ', $key, 2);
            }
            
            $clauses[] = sprintf("%s %s ?", 
                self::quoteIdentifier($field), 
                self::validateOperator($operator)
            );
            $params[] = $value;
        }
        
        return [
            $clauses ? implode(' AND ', $clauses) : '1=1',
            $params
        ];
    }

    private static function buildSet(array $data): array {
        $set = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $set[] = sprintf("%s = ?", self::quoteIdentifier($key));
            $params[] = $value;
        }
        
        return [implode(', ', $set), $params];
    }

    private static function cachedQuery(string $sql, array $params): array {
        $cacheKey = self::generateCacheKey($sql, $params);
        
        if (self::$config['enable_cache'] && self::$cache) {
            $cached = self::$cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $stmt = self::execute($sql, $params);
        $result = $stmt->fetchAll();

        if (self::$config['enable_cache'] && self::$cache) {
            self::$cache->set($cacheKey, $result);
        }

        return $result;
    }

    private static function execute(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = self::$instance->prepare($sql);
            $stmt->execute(self::normalizeParams($params));
            return $stmt;
        } catch (PDOException $e) {
            self::handleError($e, $sql, $params);
            throw $e;
        }
    }

    // ================ SECURITY & VALIDATION ================ //

    private static function validateTableName(string $table): void {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Invalid table name: $table");
        }
    }

    private static function validateOperator(string $operator): string {
        $allowed = ['=', '<>', '!=', '<', '>', '<=', '>=', 'LIKE', 'IN'];
        if (!in_array(strtoupper($operator), $allowed)) {
            throw new InvalidArgumentException("Invalid operator: $operator");
        }
        return $operator;
    }

    private static function quoteIdentifier(string $identifier): string {
        return self::$driver->getQuoteChar() . $identifier . self::$driver->getQuoteChar();
    }

    private static function normalizeParams(array $params): array {
        return array_map(function($value) {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            if (is_bool($value)) {
                return self::$driver->convertBoolean($value);
            }
            return $value;
        }, $params);
    }

    // ================ ERROR HANDLING ================ //

    private static function handleError(PDOException $e, string $sql, array $params): void {
        $message = sprintf("[DB Error] %s\nSQL: %s\nParams: %s",
            $e->getMessage(),
            $sql,
            json_encode($params)
        );

        error_log($message);

        if (self::$config['debug']) {
            throw new RuntimeException($message);
        }
    }

    private static function generateCacheKey(string $sql, array $params): string {
        return md5($sql . json_encode($params));
    }
}
