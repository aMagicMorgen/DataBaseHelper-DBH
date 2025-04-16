**Примеры использования класса `aMagicDBH` для разных СУБД:**

---

### 1. Подключение к разным СУБД
#### Для SQLite:
```php
aMagicDBH::connect([
    'sqlite' => __DIR__.'/database.db',
    'timeout' => 10,
    'debug' => true
]);
```

#### Для MySQL/MariaDB:
```php
aMagicDBH::connect([
    'host' => 'localhost',
    'dbname' => 'my_database',
    'user' => 'root',
    'pass' => 'secure_password',
    'charset' => 'utf8mb4'
]);
```

---

### 2. CRUD-операции (работают для всех СУБД!)
#### Вставка данных:
```php
$newUserId = aMagicDBH::insert('users', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'is_active' => true, // авто-конвертация в 1/0
    'created_at' => new DateTime() // авто-конвертация в строку
]);
```

#### Выборка данных с условиями:
```php
$activeUsers = aMagicDBH::select('users', [
    'is_active' => true,
    'created_at >' => '2023-01-01',
    'age BETWEEN' => [18, 65]
], 'id, name, email', 'ORDER BY name ASC', 100, 20);
```

#### Обновление данных:
```php
$affectedRows = aMagicDBH::update('products', [
    'price' => 99.99,
    'stock' => 50
], [
    'category_id' => 5,
    'price <' => 100
]);
```

#### Удаление данных:
```php
$deletedCount = aMagicDBH::delete('logs', [
    'created_at <' => '2020-01-01'
]);
```

---

### 3. Транзакции (автоматически адаптируются под СУБД)
```php
try {
    aMagicDBH::transaction(function() {
        // Перенос средств между счетами
        aMagicDBH::update('accounts', 
            ['balance' => 500], 
            ['id' => 1]
        );
        
        aMagicDBH::update('accounts', 
            ['balance' => 1500], 
            ['id' => 2]
        );
        
        aMagicDBH::insert('transactions', [
            'from_account' => 1,
            'to_account' => 2,
            'amount' => 1000
        ]);
    });
} catch (RuntimeException $e) {
    echo "Ошибка транзакции: " . $e->getMessage();
}
```

---

### 4. Агрегатные функции
#### Подсчет записей:
```php
$totalUsers = aMagicDBH::count('users', [
    'country' => 'US',
    'registration_date >' => '2023-01-01'
]);
```

#### Суммирование значений:
```php
$totalSales = aMagicDBH::sum('orders', 'amount', [
    'status' => 'completed',
    'year' => 2023
]);
```

---

### 5. Специфичные операции для СУБД
#### Оптимизация базы:
```php
// Для SQLite выполнит VACUUM, для MySQL - OPTIMIZE TABLE
aMagicDBH::optimize();
```

#### Работа с датами (кросс-платформенно):
```php
// Получение текущей даты/времени в SQL-формате
$currentDate = aMagicDBH::now();

// Использование в запросах
$recentOrders = aMagicDBH::select('orders', [
    'created_at >' => aMagicDBH::now() . ' - INTERVAL 7 DAY'
]);
```

---

### 6. Работа с кэшем
```php
// Подключение кэша
aMagicDBH::setCache(new RedisCache());

// Автоматическое кэширование
$products = aMagicDBH::select('products', 
    ['category' => 'electronics'], 
    'id, name, price', 
    'ORDER BY price DESC', 
    50
);

// Ручная очистка кэша
aMagicDBH::clearCache(
    'SELECT id, name, price FROM products WHERE category = ? ORDER BY price DESC LIMIT 50',
    ['electronics']
);
```

---

### 7. Создание таблиц (универсальный синтаксис)
```php
aMagicDBH::execute("
    CREATE TABLE IF NOT EXISTS " . aMagicDBH::quoteIdentifier('users') . " (
        id INTEGER PRIMARY KEY " . (new MySQLDriver)->autoIncrement() . ",
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE,
        created_at DATETIME DEFAULT " . aMagicDBH::now() . "
    )
");
```

---

### 8. Расширенное использование (SQLite специфика)
```php
// Включение поддержки иностранных ключей (только для SQLite)
if (aMagicDBH::getDriver() instanceof SQLiteDriver) {
    aMagicDBH::execute('PRAGMA foreign_keys = ON');
}

// Пример сложного запроса
$report = aMagicDBH::select(
    ['orders' => 'o', 'users' => 'u'], 
    [
        'o.user_id = u.id',
        'o.status' => 'completed',
        'u.country' => 'DE'
    ],
    'u.name, COUNT(o.id) as order_count, SUM(o.amount) as total_spent',
    'GROUP BY u.id HAVING total_spent > 1000'
);
```

---

**Особенности работы:**
1. Для SQLite автоматически активируется WAL-режим
2. Для MySQL/MariaDB устанавливается строгий режим (STRICT_ALL_TABLES)
3. Все даты конвертируются в UTC автоматически
4. Булевы значения преобразуются в 1/0
5. Поддержка сложных условий (BETWEEN, LIKE, IN)
6. Автоматическая обработка NULL-значений

**Совет:** Для миграций между СУБД достаточно изменить параметры подключения — весь остальной код останется неизменным!
