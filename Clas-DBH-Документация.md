# Документация класса `DB`

## Описание
Класс `DB` предоставляет удобный интерфейс для работы с базой данных через PDO с поддержкой:
- **CRUD-операций**
- **Транзакций**
- **Кэширования результатов**
- **Bulk-операций**
- **Агрегационных функций**

Реализует шаблон Singleton для единой точки доступа к PDO.

## Требования
- PHP 8.1+
- PDO расширение
- Memcached (опционально, для кэширования)

## Установка и конфигурация

### Инициализация PDO
```php
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'password');
DB::setInstance($pdo);
```

### Настройка кэширования
```php
$cache = new MemcachedCache();
DB::setCache($cache);
```

## Основные методы

### 1. `DB::insert()`
**Добавление записи:**
```php
$userId = DB::insert('users', [
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'created_at' => new DateTime()
]);
```
- Возвращает ID последней вставленной записи.

### 2. `DB::select()`
**Простой запрос:**
```php
$users = DB::select('users');
```

**С условиями:**
```php
$activeUsers = DB::select('users', [
    'status' => 'active',
    'age >' => 18
], 'id, name', 'ORDER BY id DESC', 10);
```

### 3. `DB::first()`
**Получение первой записи:**
```php
$user = DB::first('users', ['id' => 42]);
// Возвращает null если не найдено
```

### 4. `DB::update()`
**Обновление данных:**
```php
$affectedRows = DB::update('users', 
    ['status' => 'banned'], 
    ['email' => 'spammer@example.com']
);
```

### 5. `DB::delete()`
**Удаление записей:**
```php
$deletedCount = DB::delete('users', [
    'status' => 'inactive',
    'last_login <' => '2023-01-01'
]);
```

## Кэширование

### Автоматическое кэширование
Результаты `SELECT` автоматически кэшируются:
```php
// Первый вызов - запрос к БД
$users = DB::select('users', ['status' => 'active']);

// Последующие вызовы - данные из кэша
$cachedUsers = DB::select('users', ['status' => 'active']);
```

### Очистка кэша
```php
DB::clearCache("SELECT * FROM users WHERE status = 'active'");
```

## Транзакции
```php
DB::transaction(function() {
    DB::update('accounts', ['balance' => 500], ['id' => 1]);
    DB::update('accounts', ['balance' => 1500], ['id' => 2]);
}, 'READ COMMITTED');
```

## Bulk-операции
**Массовая вставка:**
```php
DB::bulkInsert('products', [
    ['name' => 'Phone', 'price' => 599],
    ['name' => 'Laptop', 'price' => 1299],
    ['name' => 'Tablet', 'price' => 299]
]);
```

## Агрегационные функции
```php
// Количество активных пользователей
$count = DB::count('users', ['status' => 'active']);

// Суммарный баланс
$total = DB::sum('accounts', 'balance');
```

## Обработка ошибок
- При ошибках PDO бросает `PDOException`
- Ошибки логируются в error_log
- Кэш автоматически очищается при ошибках запросов

## Примечания

1. **Санатизация идентификаторов:**
   ```php
   // Автоматически преобразует в `column_name`
   DB::select('my_table', ['invalid!column' => 5]); // Выбросит исключение
   ```

2. **Типы данных:**
   - `DateTime` автоматически конвертируется в строку
   - Булевы значения преобразуются в 1/0

3. **Генерация условий:**
   ```php
   // Поддерживает операторы:
   DB::select('users', ['age >' => 18]);
   
   // IN-условия:
   DB::select('products', ['id' => [1, 5, 9]]);
   ```

4. **Произвольные SQL-запросы:**
   ```php
   $result = DB::query("SHOW TABLES LIKE ?", ['users%'])->fetchAll();
   ```

## Полный пример
```php
// Конфигурация
$pdo = new PDO('mysql:host=localhost;dbname=shop', 'user', 'pass');
DB::setInstance($pdo);
DB::setCache(new MemcachedCache());

// Создание заказа
DB::transaction(function() {
    $orderId = DB::insert('orders', [
        'user_id' => 123,
        'total' => 99.99
    ]);
    
    DB::bulkInsert('order_items', [
        ['order_id' => $orderId, 'product_id' => 55],
        ['order_id' => $orderId, 'product_id' => 89]
    ]);
});

// Получение данных
$recentOrders = DB::select(
    'orders',
    ['created_at >' => '2024-01-01'],
    'id, total',
    'ORDER BY created_at DESC',
    20
);
```
