<?php

namespace App\Libraries;

use PDO;

/** Institutes and the project-work link used when a client submits a ticket. */
final class InstituteSchema
{
    public static function migrate(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $db->exec($mysql
            ? 'CREATE TABLE IF NOT EXISTS institutes (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(120) NOT NULL, created_at VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : 'CREATE TABLE IF NOT EXISTS institutes (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, created_at TEXT NOT NULL)');
        foreach (['projects', 'issues'] as $table) {
            $columns = $mysql
                ? array_column($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC), 'Field')
                : array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('institute_id', $columns, true)) {
                $db->exec('ALTER TABLE `' . $table . '` ADD COLUMN institute_id ' . ($mysql ? 'INT NULL' : 'INTEGER'));
            }
        }
    }
}
