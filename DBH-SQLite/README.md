# Полная документация по классу `DBHsqlite`

## Оглавление
1. **Подключение и настройка**
2. **CRUD-операции**
3. **Транзакции**
4. **Пакетные операции**
5. **Агрегатные функции**
6. **Кэширование**
7. **Специфичные методы SQLite**
8. **Вспомогательные методы**
9. **Пример комплексного использования**

---

## 1. Подключение и настройка

### `configure()`
**Назначение**: Инициализация подключения к SQLite базе.  
**Параметры**:
- `path` (string): Путь к файлу БД
- `mode` (string, опционально): Режим доступа (`r`/`rw`/`rwc`)

```php
// Создание/открытие БД в режиме чтения-записи
DBHsqlite::configure(__DIR__.'/database.db', 'rw');

// Автоматическая настройка WAL-режима журналирования
```

---

## 2. CRUD-операции

### `insert()`
**Назначение**: Вставка записи в таблицу.  
**Параметры**:
- `table` (string): Имя таблицы
- `data` (array): Данные для вставки

**Возвращает**: ID последней вставленной записи.

```php
$id = DBHsqlite::insert('users', [
    'name' => 'Bob',
    'age' => 25,
    'created_at' => new DateTime()
]);
```

### `select()`
**Назначение**: Выборка данных.  
**Параметры**:
- `table` (string): Имя таблицы
- `conditions` (array): Условия выборки
- `columns` (string): Список колонок
- `order` (string): Сортировка
- `limit` (int): Лимит записей
- `offset` (int): Смещение

```php
$users = DBHsqlite::select(
    'users',
    [
        'age >=' => 18,
        'name LIKE' => 'A%'
    ],
    'id, name, age',
    'ORDER BY age DESC',
    10,
    5
);
```

### `update()`
**Назначение**: Обновление записей.  
**Параметры**:
- `table` (string): Имя таблицы
- `data` (array): Новые данные
- `conditions` (array): Условия обновления

```php
$affected = DBHsqlite::update(
    'users',
    ['status' => 'active'],
    ['id' => 123]
);
```

### `delete()`
**Назначение**: Удаление записей.  
**Параметры**:
- `table` (string): Имя таблицы
- `conditions` (array): Условия удаления

```php
$deleted = DBHsqlite::delete('users', [
    'status' => 'banned',
    'last_login <' => '2020-01-01'
]);
```

---

## 3. Транзакции

### `transaction()`
**Назначение**: Атомарное выполнение операций.  
**Параметры**:
- `callback` (callable): Функция с операциями
- `isolationLevel` (string): Уровень изоляции (`SERIALIZABLE`/`READ UNCOMMITTED`)

```php
DBHsqlite::transaction(function() {
    DBHsqlite::insert('logs', ['event' => 'start']);
    DBHsqlite::update('counters', ['value' => 100], ['id' => 1]);
});
```

---

## 4. Пакетные операции

### `bulkInsert()`
**Назначение**: Массовая вставка данных.  
**Параметры**:
- `table` (string): Имя таблицы
- `rows` (array): Массив данных

```php
$data = [];
for ($i = 1; $i <= 1000; $i++) {
    $data[] = [
        'product_id' => $i,
        'price' => rand(10, 100)
    ];
}

DBHsqlite::bulkInsert('prices', $data);
```

---

## 5. Агрегатные функции

### `count()`
**Назначение**: Подсчет записей.  
**Параметры**:
- `table` (string): Имя таблицы
- `conditions` (array): Условия выборки

```php
$total = DBHsqlite::count('orders', [
    'status' => 'completed',
    'date >=' => '2023-01-01'
]);
```

### `sum()`
**Назначение**: Сумма значений колонки.  
**Параметры**:
- `table` (string): Имя таблицы
- `column` (string): Имя колонки
- `conditions` (array): Условия выборки

```php
$total = DBHsqlite::sum('sales', 'amount', [
    'year' => 2023,
    'quarter' => 4
]);
```

---

## 6. Кэширование

### `setCache()`
**Назначение**: Подключение системы кэширования.  
**Параметры**:
- `cache` (Cache): Объект кэша

```php
DBHsqlite::setCache(new RedisCache());
```

### `clearCache()`
**Назначение**: Очистка кэша для запроса.  
**Параметры**:
- `sql` (string): SQL-запрос
- `params` (array): Параметры запроса

```php
DBHsqlite::clearCache(
    'SELECT * FROM users WHERE age > ?',
    [18]
);
```

---

## 7. Специфичные методы SQLite

### `vacuum()`
**Назначение**: Оптимизация размера файла БД.  
```php
// Запуск процесса VACUUM
DBHsqlite::vacuum();
```

### `enableForeignKeys()`
**Назначение**: Включение проверки внешних ключей.  
**Параметры**:
- `enable` (bool): Активация (по умолчанию `true`)

```php
// Включение проверки FK
DBHsqlite::enableForeignKeys();

// Отключение проверки FK
DBHsqlite::enableForeignKeys(false);
```

---

## 8. Вспомогательные методы

### `createTable()`
**Назначение**: Создание таблицы.  
**Параметры**:
- `name` (string): Имя таблицы
- `columns` (array): Колонки

```php
DBHsqlite::createTable('posts', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'title' => 'TEXT NOT NULL',
    'content' => 'TEXT',
    'created_at' => 'TEXT DEFAULT CURRENT_TIMESTAMP'
]);
```

---

## 9. Пример комплексного использования

```php
// Инициализация
DBHsqlite::configure('/var/db/app.db');
DBHsqlite::setCache(new FileCache());

// Создание таблицы
DBHsqlite::createTable('articles', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'title' => 'TEXT UNIQUE',
    'views' => 'INTEGER DEFAULT 0'
]);

// Транзакционная вставка
DBHsqlite::transaction(function() {
    $articleId = DBHsqlite::insert('articles', [
        'title' => 'SQLite Guide',
        'views' => 100
    ]);
    
    DBHsqlite::insert('stats', [
        'article_id' => $articleId,
        'event' => 'create'
    ]);
});

// Выборка с кэшированием
$popular = DBHsqlite::select(
    'articles',
    ['views >=' => 50],
    'id, title',
    'ORDER BY views DESC',
    10
);

// Обновление данных
DBHsqlite::update('articles', 
    ['views' => 150],
    ['title' => 'SQLite Guide']
);

// Оптимизация БД
DBHsqlite::vacuum();
```

---

## Особенности SQLite:
- **Динамическая типизация**: Нет строгих типов колонок
- **Однопользовательский доступ**: Блокировка на уровне файла
- **Лимиты**: 
  - Макс. размер БД: ~140 TB
  - Макс. длина строки: 1 млрд символов
- **Функции даты**: Используйте `strftime()`
- **Автоинкремент**: Только для `INTEGER PRIMARY KEY`

**Пример работы с датами**:
```php
// Вставка
DBHsqlite::insert('events', [
    'name' => 'Meeting',
    'date' => (new DateTime('+1 week'))->format('Y-m-d H:i:s')
]);

// Выборка
$events = DBHsqlite::select(
    'events',
    ["strftime('%Y', date) = ?" => '2023'],
    'id, name, date'
);
```
