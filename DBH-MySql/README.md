# DataBaseHelper-DBH

# Документация по классу DBH (DatabaseHelper) версия 1.3

## Введение

Класс `DBH` предоставляет удобный интерфейс для работы с базой данных через PDO с поддержкой кэширования, транзакций и CRUD-операций. Реализует шаблон Singleton для управления соединением с базой данных.

## Основные методы

### `setConnectionTimeout(int $seconds): void`
Устанавливает таймаут соединения с базой данных.

**Параметры:**
- `$seconds` - Таймаут в секундах (от 1 до 30)

**Пример:**
```php
DBH::setConnectionTimeout(10); // Установка таймаута 10 секунд
```

### `setInstance(PDO $pdo): void`
Устанавливает экземпляр PDO для работы с базой данных.

**Параметры:**
- `$pdo` - Экземпляр PDO

**Пример:**
```php
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'password');
DBH::setInstance($pdo);
```

### `instance(): PDO`
Возвращает экземпляр PDO.

**Пример:**
```php
$pdo = DBH::instance();
```

### `setCache(Cache $cache): void`
Устанавливает реализацию кэша.

**Параметры:**
- `$cache` - Объект, реализующий интерфейс Cache

**Пример:**
```php
$cache = new MemcachedCache();
DBH::setCache($cache);
```

### `clearCache(string $sql, array $params = []): void`
Очищает кэш для указанного SQL-запроса.

**Параметры:**
- `$sql` - SQL-запрос
- `$params` - Параметры запроса

**Пример:**
```php
DBH::clearCache("SELECT * FROM users WHERE id = ?", [1]);
```

## CRUD-операции

### `query(string $sql, array $params = []): PDOStatement`
Выполняет произвольный SQL-запрос.

**Параметры:**
- `$sql` - SQL-запрос
- `$params` - Параметры запроса

**Пример:**
```php
$stmt = DBH::query("SELECT * FROM users WHERE age > ?", [18]);
$users = $stmt->fetchAll();
```

### `first(string $table, array $conditions = [], string $columns = '*'): ?array`
Возвращает первую запись из таблицы, соответствующую условиям.

**Параметры:**
- `$table` - Имя таблицы
- `$conditions` - Условия выборки
- `$columns` - Список колонок для выборки

**Пример:**
```php
$user = DBH::first('users', ['id' => 1]);
```

### `insert(string $table, array $data): string`
Добавляет новую запись в таблицу.

**Параметры:**
- `$table` - Имя таблицы
- `$data` - Данные для вставки

**Возвращает:** ID последней вставленной записи

**Пример:**
```php
$id = DBH::insert('users', [
    'name' => 'John',
    'email' => 'john@example.com',
    'age' => 30
]);
```

### `update(string $table, array $data, array $conditions): int`
Обновляет записи в таблице.

**Параметры:**
- `$table` - Имя таблицы
- `$data` - Данные для обновления
- `$conditions` - Условия для обновления

**Возвращает:** Количество обновленных строк

**Пример:**
```php
$affected = DBH::update('users', ['age' => 31], ['id' => 1]);
```

### `delete(string $table, array $conditions): int`
Удаляет записи из таблицы.

**Параметры:**
- `$table` - Имя таблицы
- `$conditions` - Условия для удаления

**Возвращает:** Количество удаленных строк

**Пример:**
```php
$deleted = DBH::delete('users', ['id' => 1]);
```

### `select(string $table, array $conditions = [], string $columns = '*', string $order = '', int $limit = 0, int $offset = 0, int $cacheTTL = 3600): array`
Выполняет SELECT-запрос к таблице.

**Параметры:**
- `$table` - Имя таблицы
- `$conditions` - Условия выборки
- `$columns` - Список колонок для выборки
- `$order` - Сортировка
- `$limit` - Лимит записей
- `$offset` - Смещение
- `$cacheTTL` - Время жизни кэша в секундах

**Пример:**
```php
$users = DBH::select(
    'users', 
    ['age >' => 18], 
    'id, name, email', 
    'name ASC', 
    10, 
    0,
    600 // Кэшировать на 10 минут
);
```

## Транзакции

### `transaction(callable $callback, string $isolationLevel = 'SERIALIZABLE'): mixed`
Выполняет код в транзакции.

**Параметры:**
- `$callback` - Функция для выполнения в транзакции
- `$isolationLevel` - Уровень изоляции

**Пример:**
```php
DBH::transaction(function() {
    DBH::update('accounts', ['balance' => 90], ['id' => 1]);
    DBH::update('accounts', ['balance' => 110], ['id' => 2]);
});
```

## Bulk-операции

### `bulkInsert(string $table, array $rows): void`
Массовая вставка данных.

**Параметры:**
- `$table` - Имя таблицы
- `$rows` - Массив данных для вставки

**Пример:**
```php
DBH::bulkInsert('users', [
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com']
]);
```

## Агрегатные функции

### `count(string $table, array $conditions = []): int`
Подсчет количества записей.

**Пример:**
```php
$count = DBH::count('users', ['age >' => 18]);
```

### `sum(string $table, string $column, array $conditions = []): float`
Сумма значений колонки.

**Пример:**
```php
$total = DBH::sum('orders', 'amount', ['status' => 'completed']);
```

### `avg(string $table, string $column, array $conditions = []): float`
Среднее значение колонки.

**Пример:**
```php
$average = DBH::avg('products', 'price', ['category' => 'electronics']);
```

### `min(string $table, string $column, array $conditions = []): mixed`
Минимальное значение колонки.

**Пример:**
```php
$minPrice = DBH::min('products', 'price');
```

### `max(string $table, string $column, array $conditions = []): mixed`
Максимальное значение колонки.

**Пример:**
```php
$maxPrice = DBH::max('products', 'price');
```

## Вспомогательные методы

### `getTableColumns(string $table): array`
Возвращает список колонок таблицы.

**Пример:**
```php
$columns = DBH::getTableColumns('users');
```

### `search(string $table, string|array $searchTerms, string|array|null $columns = null, string $logic = '', string $columnsToSelect = '*'): array`
Универсальный поиск по таблице.

**Примеры:**
```php
// Поиск по всем колонкам
$results = DBH::search('users', 'john');

// Поиск по конкретным колонкам
$results = DBH::search('users', 'john doe', ['name', 'email'], 'AND');

// Поиск с несколькими терминами
$results = DBH::search('products', ['apple', 'iphone'], 'description');
```

## Заключение

Класс `DBH` предоставляет удобный и безопасный интерфейс для работы с базой данных, включая поддержку транзакций, кэширования и всех основных CRUD-операций. Использование этого класса позволяет писать более чистый и поддерживаемый код для работы с базой данных.
