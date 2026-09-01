<?php
/**
 * Migración incremental para instalaciones existentes.
 *
 * Uso:
 *   DB_HOST=localhost DB_NAME=cyberleo DB_USER=usuario DB_PASS=secreto \
 *     php migrations/001_add_orders_stock_settings.php
 *
 * Si las variables no están definidas, se usan las constantes de
 * includes/config.php.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Esta migración solo puede ejecutarse desde la CLI de PHP.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/config.php';

function migration_config_value($environmentName, $constantName)
{
    $value = getenv($environmentName);
    if ($value !== false && $value !== '') {
        return $value;
    }

    return defined($constantName) ? constant($constantName) : '';
}

function identifier($name)
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function fail($message)
{
    throw new RuntimeException($message);
}

function table_exists(PDO $pdo, $table)
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $statement->execute(array($table));

    return (bool) $statement->fetchColumn();
}

function table_engine(PDO $pdo, $table)
{
    $statement = $pdo->prepare(
        'SELECT engine FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $statement->execute(array($table));

    return $statement->fetchColumn();
}

function column(PDO $pdo, $table, $columnName)
{
    $statement = $pdo->prepare(
        'SELECT column_type, is_nullable FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $statement->execute(array($table, $columnName));
    $result = $statement->fetch(PDO::FETCH_ASSOC);

    return $result ?: null;
}

function require_column(PDO $pdo, $table, $columnName, $type, $nullable)
{
    $actual = column($pdo, $table, $columnName);
    if ($actual === null) {
        fail("Incompatibilidad: falta la columna {$table}.{$columnName}; la migración no puede inventar su estructura existente.");
    }

    $actualType = preg_replace('/\(\d+\)/', '', strtolower($actual['column_type']));
    $expectedType = preg_replace('/\(\d+\)/', '', strtolower($type));
    if ($actualType !== $expectedType
        || $actual['is_nullable'] !== ($nullable ? 'YES' : 'NO')) {
        fail(
            "Incompatibilidad: {$table}.{$columnName} es {$actual['column_type']} "
            . ($actual['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL')
            . "; se esperaba {$type} " . ($nullable ? 'NULL' : 'NOT NULL') . '.'
        );
    }
}

function require_innodb(PDO $pdo, $table)
{
    if (strcasecmp((string) table_engine($pdo, $table), 'InnoDB') !== 0) {
        fail("Incompatibilidad: {$table} debe usar el motor InnoDB para añadir claves foráneas.");
    }
}

function has_index_starting_with(PDO $pdo, $table, $columnName)
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ?
           AND column_name = ? AND seq_in_index = 1
         LIMIT 1'
    );
    $statement->execute(array($table, $columnName));

    return (bool) $statement->fetchColumn();
}

function has_unique_index_starting_with(PDO $pdo, $table, $columnName)
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ?
           AND column_name = ? AND seq_in_index = 1 AND non_unique = 0
         LIMIT 1'
    );
    $statement->execute(array($table, $columnName));

    return (bool) $statement->fetchColumn();
}

function add_index_if_missing(PDO $pdo, $table, $indexName, $columnName)
{
    if (!has_index_starting_with($pdo, $table, $columnName)) {
        $pdo->exec(
            'ALTER TABLE ' . identifier($table)
            . ' ADD INDEX ' . identifier($indexName) . ' (' . identifier($columnName) . ')'
        );
        fwrite(STDOUT, "Índice {$indexName} creado.\n");
    }
}

function foreign_key(PDO $pdo, $table, $columnName)
{
    $statement = $pdo->prepare(
        'SELECT kcu.constraint_name, kcu.referenced_table_name, kcu.referenced_column_name,
                rc.delete_rule
         FROM information_schema.key_column_usage kcu
         JOIN information_schema.referential_constraints rc
           ON rc.constraint_schema = kcu.constraint_schema
          AND rc.table_name = kcu.table_name
          AND rc.constraint_name = kcu.constraint_name
         WHERE kcu.table_schema = DATABASE() AND kcu.table_name = ?
           AND kcu.column_name = ? AND kcu.referenced_table_name IS NOT NULL'
    );
    $statement->execute(array($table, $columnName));

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function add_foreign_key_if_compatible(
    PDO $pdo,
    $table,
    $columnName,
    $constraintName,
    $referencedTable,
    $referencedColumn,
    $deleteRule
) {
    $existing = foreign_key($pdo, $table, $columnName);
    foreach ($existing as $foreignKey) {
        if ($foreignKey['referenced_table_name'] === $referencedTable
            && $foreignKey['referenced_column_name'] === $referencedColumn
            && strtoupper($foreignKey['delete_rule']) === $deleteRule) {
            return;
        }
    }

    if ($existing) {
        fail(
            "Incompatibilidad: {$table}.{$columnName} ya tiene una clave foránea "
            . 'distinta; no se modifica una restricción existente.'
        );
    }

    require_innodb($pdo, $table);
    require_innodb($pdo, $referencedTable);
    require_column($pdo, $table, $columnName, 'int unsigned', $deleteRule === 'SET NULL');
    require_column($pdo, $referencedTable, $referencedColumn, 'int unsigned', false);
    if (!has_unique_index_starting_with($pdo, $referencedTable, $referencedColumn)) {
        fail("Incompatibilidad: {$referencedTable}.{$referencedColumn} necesita un índice UNIQUE o PRIMARY.");
    }

    $orphanQuery = 'SELECT 1 FROM ' . identifier($table) . ' AS child'
        . ' LEFT JOIN ' . identifier($referencedTable) . ' AS parent'
        . ' ON child.' . identifier($columnName) . ' = parent.' . identifier($referencedColumn)
        . ' WHERE child.' . identifier($columnName) . ' IS NOT NULL'
        . ' AND parent.' . identifier($referencedColumn) . ' IS NULL LIMIT 1';
    if ($pdo->query($orphanQuery)->fetchColumn()) {
        fail(
            "Incompatibilidad: existen valores huérfanos en {$table}.{$columnName}; "
            . 'la migración no modifica datos para corregirlos.'
        );
    }

    add_index_if_missing($pdo, $table, 'idx_' . $table . '_' . $columnName, $columnName);
    $pdo->exec(
        'ALTER TABLE ' . identifier($table)
        . ' ADD CONSTRAINT ' . identifier($constraintName)
        . ' FOREIGN KEY (' . identifier($columnName) . ')'
        . ' REFERENCES ' . identifier($referencedTable) . ' (' . identifier($referencedColumn) . ')'
        . ' ON DELETE ' . $deleteRule
    );
    fwrite(STDOUT, "Clave foránea {$constraintName} creada.\n");
}

try {
    $host = migration_config_value('DB_HOST', 'DB_HOST');
    $database = migration_config_value('DB_NAME', 'DB_NAME');
    $user = migration_config_value('DB_USER', 'DB_USER');
    $password = migration_config_value('DB_PASS', 'DB_PASS');
    if ($host === '' || $database === '' || $user === '') {
        fail('Faltan DB_HOST, DB_NAME o DB_USER. Defínalos en el entorno o en includes/config.php.');
    }

    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4',
        $user,
        $password,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    if (!table_exists($pdo, 'products')) {
        fail('Incompatibilidad: falta la tabla products. Importe schema.sql para una instalación nueva.');
    }
    require_innodb($pdo, 'products');
    require_column($pdo, 'products', 'id', 'int unsigned', false);
    if (!has_unique_index_starting_with($pdo, 'products', 'id')) {
        fail('Incompatibilidad: products.id necesita un índice UNIQUE o PRIMARY.');
    }

    if (column($pdo, 'products', 'stock') === null) {
        $pdo->exec('ALTER TABLE `products` ADD COLUMN `stock` INT NOT NULL DEFAULT 0');
        fwrite(STDOUT, "Columna products.stock creada.\n");
    } else {
        require_column($pdo, 'products', 'stock', 'int', false);
    }
    if (column($pdo, 'products', 'is_active') === null) {
        $pdo->exec('ALTER TABLE `products` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1');
        fwrite(STDOUT, "Columna products.is_active creada.\n");
    } else {
        require_column($pdo, 'products', 'is_active', 'tinyint(1)', false);
    }

    if (!table_exists($pdo, 'orders')) {
        $pdo->exec(
            "CREATE TABLE `orders` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `status` ENUM('pending','confirmed','cancelled','expired') NOT NULL DEFAULT 'pending',
                `total` DECIMAL(12,2) NOT NULL,
                `idempotency_key` CHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_orders_idempotency_key` (`idempotency_key`),
                KEY `idx_orders_status_expires` (`status`, `expires_at`)
            ) ENGINE=InnoDB"
        );
        fwrite(STDOUT, "Tabla orders creada.\n");
    }
    require_innodb($pdo, 'orders');
    require_column($pdo, 'orders', 'id', 'int unsigned', false);
    if (column($pdo, 'orders', 'idempotency_key') === null) $pdo->exec("ALTER TABLE `orders` ADD COLUMN `idempotency_key` CHAR(64) NULL");
    if (column($pdo, 'orders', 'expires_at') === null) $pdo->exec("ALTER TABLE `orders` ADD COLUMN `expires_at` DATETIME NULL");
    require_column($pdo, 'orders', 'status', "enum('pending','confirmed','cancelled','expired')", false);
    require_column($pdo, 'orders', 'total', 'decimal(12,2)', false);
    add_index_if_missing($pdo, 'orders', 'idx_orders_status', 'status');

    if (!table_exists($pdo, 'order_items')) {
        $pdo->exec(
            'CREATE TABLE `order_items` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `order_id` INT UNSIGNED NOT NULL,
                `product_id` INT UNSIGNED NULL,
                `product_name` VARCHAR(190) NOT NULL,
                `unit_price` DECIMAL(12,2) NOT NULL,
                `quantity` INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB'
        );
        fwrite(STDOUT, "Tabla order_items creada.\n");
    }
    require_innodb($pdo, 'order_items');
    require_column($pdo, 'order_items', 'id', 'int unsigned', false);
    require_column($pdo, 'order_items', 'order_id', 'int unsigned', false);
    require_column($pdo, 'order_items', 'product_id', 'int unsigned', true);
    require_column($pdo, 'order_items', 'product_name', 'varchar(190)', false);
    require_column($pdo, 'order_items', 'unit_price', 'decimal(12,2)', false);
    require_column($pdo, 'order_items', 'quantity', 'int unsigned', false);

    if (!table_exists($pdo, 'store_settings')) {
        $pdo->exec(
            'CREATE TABLE `store_settings` (
                `setting_key` VARCHAR(80) PRIMARY KEY,
                `setting_value` TEXT NOT NULL
            ) ENGINE=InnoDB'
        );
        fwrite(STDOUT, "Tabla store_settings creada.\n");
    }
    if (!table_exists($pdo, 'order_rate_limits')) {
        $pdo->exec('CREATE TABLE `order_rate_limits` (`client_hash` CHAR(64) NOT NULL, `requested_at` DATETIME NOT NULL, KEY `idx_rate_client_time` (`client_hash`, `requested_at`)) ENGINE=InnoDB');
    }
    if (!table_exists($pdo, 'auth_rate_limits')) {
        $pdo->exec('CREATE TABLE `auth_rate_limits` (`context_hash` CHAR(64) NOT NULL, `requested_at` DATETIME NOT NULL, KEY `idx_auth_rate_time` (`context_hash`, `requested_at`)) ENGINE=InnoDB');
    }
    require_innodb($pdo, 'store_settings');
    require_column($pdo, 'store_settings', 'setting_key', 'varchar(80)', false);
    require_column($pdo, 'store_settings', 'setting_value', 'text', false);
    if (!has_unique_index_starting_with($pdo, 'store_settings', 'setting_key')) {
        fail('Incompatibilidad: store_settings.setting_key necesita un índice UNIQUE o PRIMARY.');
    }

    add_foreign_key_if_compatible(
        $pdo, 'order_items', 'order_id', 'fk_order_item_order', 'orders', 'id', 'CASCADE'
    );
    add_foreign_key_if_compatible(
        $pdo, 'order_items', 'product_id', 'fk_order_item_product', 'products', 'id', 'SET NULL'
    );

    fwrite(STDOUT, "Migración completada sin modificar ni eliminar datos existentes.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Migración detenida: " . $exception->getMessage() . "\n");
    exit(1);
}
