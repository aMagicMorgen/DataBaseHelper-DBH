# Полная документация по классу DBHmariaDB

## Оглавление
1. **Подключение и настройка**
2. **CRUD-операции**
3. **Транзакции**
4. **Пакетные операции**
5. **Агрегатные функции**
6. **Кэширование**
7. **Специфичные методы MariaDB**
8. **Вспомогательные методы**
9. **Примеры использования**

---

## 1. Подключение и настройка

### `configure()`
**Назначение**: Инициализация подключения к базе данных.  
**Параметры**:
- `host` (string): Хост БД
- `dbname` (string): Имя базы данных
- `user` (string): Пользователь БД
- `pass` (string): Пароль
- `charset` (string, опционально): Кодировка (по умолчанию 'utf8mb4')

```php
DBHmariaDB::configure('localhost', 'my_database', 'user', 'password');
```

### `setConnectionTimeout()`
**Назначение**: Установка таймаута подключения.  
**Параметры**:
- `seconds` (int): Таймаут в секундах

```php
DBHmariaDB::setConnectionTimeout(10);
```

---

## 2. CRUD-операции

### `insert()`
**Назначение**: Вставка записи в таблицу.  
**Параметры**:
- `table` (string): Имя таблицы
- `data` (array): Данные для вставки в формате `[поле => значение]`

**Возвращает**: ID последней вставленной записи.

```php
$id = DBHmariaDB::insert('users', [
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);
```

### `select()`
**Назначение**: Выборка данных.  
**Параметры**:
- `table` (string): Имя таблицы
- `conditions` (array, опционально): Условия выборки
- `columns` (string, опционально): Список колонок (по умолчанию '*')
- `order` (string, опционально): Сортировка
- `limit` (int, опционально): Лимит записей
- `offset` (int, опционально): Смещение

**Возвращает**: Массив записей.

```php
$users = DBHmariaDB::select(
    'users',
    ['status' => 'active', 'age >=' => 18],
    'id, name',
    'ORDER BY id DESC',
    10
);
```

### `update()`
**Назначение**: Обновление записей.  
**Параметры**:
- `table` (string): Имя таблицы
- `data` (array): Новые данные
- `conditions` (array): Условия обновления

**Возвращает**: Количество измененных строк.

```php
$affected = DBHmariaDB::update(
    'users',
    ['status' => 'banned'],
    ['id' => 123]
);
```

### `delete()`
**Назначение**: Удаление записей.  
**Параметры**:
- `table` (string): Имя таблицы
- `conditions` (array): Условия удаления

**Возвращает**: Количество удаленных строк.

```php
$deleted = DBHmariaDB::delete('users', ['id' => 456]);
```

---

## 3. Транзакции

### `transaction()`
**Назначение**: Выполнение операций в транзакции.  
**Параметры**:
- `callback` (callable): Функция с операциями
- `isolationLevel` (string, опционально): Уровень изоляции

```php
DBHmariaDB::transaction(function() {
    DBHmariaDB::insert('logs', ['event' => 'start']);
    DBHmariaDB::update('counters', ['value' => 100], ['id' => 1]);
}, 'READ COMMITTED');
```

---

## 4. Пакетные операции

### `bulkInsert()`
**Назначение**: Массовая вставка данных.  
**Параметры**:
- `table` (string): Имя таблицы
- `rows` (array): Массив данных для вставки

```php
DBHmariaDB::bulkInsert('products', [
    ['name' => 'Phone', 'price' => 299],
    ['name' => 'Laptop', 'price' => 999]
]);
```

---

## 5. Агрегатные функции

### `count()`
**Назначение**: Подсчет записей.  
**Параметры**:
- `table` (string): Имя таблицы
- `conditions` (array, опционально): Условия

**Возвращает**: Количество записей.

```php
$total = DBHmariaDB::count('users', ['status' => 'active']);
```

### `sum()`
**Назначение**: Сумма значений колонки.  
**Параметры**:
- `table` (string): Имя таблицы
- `column` (string): Имя колонки
- `conditions` (array, опционально): Условия

**Возвращает**: Сумму значений.

```php
$totalSales = DBHmariaDB::sum('orders', 'amount', ['year' => 2023]);
```

---

## 6. Кэширование

### `setCache()`
**Назначение**: Установка кэшера.  
**Параметры**:
- `cache` (Cache): Объект кэша

```php
DBHmariaDB::setCache(new MemcachedCache());
```

### `clearCache()`
**Назначение**: Очистка кэша для запроса.  
**Параметры**:
- `sql` (string): SQL-запрос
- `params` (array, опционально): Параметры запроса

```php
DBHmariaDB::clearCache('SELECT * FROM users WHERE status = ?', ['active']);
```

---

## 7. Специфичные методы MariaDB

### `getFoundRows()`
**Назначение**: Получение общего количества строк после `SQL_CALC_FOUND_ROWS`.  
**Возвращает**: Общее количество записей.

```php
DBHmariaDB::select('products', [], 'SQL_CALC_FOUND_ROWS *', 'LIMIT 10');
$total = DBHmariaDB::getFoundRows();
```

### `setSessionVariable()`
**Назначение**: Установка сессионной переменной.  
**Параметры**:
- `name` (string): Имя переменной
- `value` (mixed): Значение

```php
DBHmariaDB::setSessionVariable('lock_timeout', 30);
```

---

## 8. Вспомогательные методы

### `createTable()`
**Назначение**: Создание таблицы.  
**Параметры**:
- `name` (string): Имя таблицы
- `columns` (array): Колонки в формате `[имя => тип]`

```php
DBHmariaDB::createTable('logs', [
    'id' => 'INT AUTO_INCREMENT',
    'message' => 'TEXT NOT NULL',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);
```

---

## 9. Примеры использования

### Полный цикл работы
```php
// Настройка
DBHmariaDB::configure('localhost', 'shop', 'admin', 'secret');
DBHmariaDB::setCache(new RedisCache());

// Создание таблицы
DBHmariaDB::createTable('products', [
    'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
    'name' => 'VARCHAR(255)',
    'price' => 'DECIMAL(10,2)'
]);

// Вставка данных
$productId = DBHmariaDB::insert('products', [
    'name' => 'Smartwatch',
    'price' => 199.99
]);

// Выборка с кэшированием
$products = DBHmariaDB::select(
    'products',
    ['price <=' => 200],
    'id, name',
    'ORDER BY price DESC'
);

// Транзакция
DBHmariaDB::transaction(function() use ($productId) {
    DBHmariaDB::update('products', ['price' => 179.99], ['id' => $productId]);
    DBHmariaDB::insert('audit', ['event' => 'price_update']);
});

// Удаление
DBHmariaDB::delete('products', ['id' => $productId]);
```

---

## Особенности:
- Все методы статические
- Автоматическое преобразование DateTime в строки
- Поддержка сложных условий в WHERE
- Оптимизация запросов через SQL_CALC_FOUND_ROWS
- Строгая типизация параметров
- Логирование ошибок с возможностью отладки (активируется через константу `DB_DEBUG`)

[![Схема работы класса](https://mermaid.ink/img/pako:eNp9kc1OwzAMgF_F8p2SbgVxQlw4oR1C4sCNU0xT1yF2pDiFie3d4VPAjqRq_2T7s51uQdZgwA1y6YJ4QHx5gU1wXmCqZkZ2C5Xb6Jw-5xTdGk3R7sD5tKb2O6h8k7tE5d7wP0H9C_4P9H-1H9B8w3mF-Qv8D5hPmB-Qv0H9A_IT8hvyC_ILyifkJ-QX5DfkF-QXlE_IT8gvyC_ILyifkJ-QX5DfkF9QPiE_Ib8gvyC_oHxCfkJ-QX5BfkH5hPyE_IL8gvyC8gn5CfkF-QX5BeUT8hPyC_IL8gvKJ-Q
