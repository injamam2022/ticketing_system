<?php

namespace App\Libraries;

use PDO;

final class StatusSchema
{
    public static function create(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $db->exec($mysql
            ? 'CREATE TABLE IF NOT EXISTS workspace_statuses (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(30) NOT NULL UNIQUE, color VARCHAR(7) NOT NULL, category VARCHAR(20) NOT NULL, position INT NOT NULL, system_key VARCHAR(30) UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : 'CREATE TABLE IF NOT EXISTS workspace_statuses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE COLLATE NOCASE, color TEXT NOT NULL, category TEXT NOT NULL, position INTEGER NOT NULL, system_key TEXT UNIQUE)');
    }

    public static function seed(PDO $db): void
    {
        $verb = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $insert = $db->prepare($verb . ' INTO workspace_statuses (name,color,category,position,system_key) VALUES (?,?,?,?,?)');
        foreach ([['Backlog','#777283','backlog'], ['Todo','#bbb5c5','todo'], ['In progress','#dfbc6d','active'], ['In review','#a893db','review'], ['Done','#80bca0','done']] as $position => $status) {
            // Stable keys keep renamed default statuses from being recreated.
            $exists = $db->prepare('SELECT id FROM workspace_statuses WHERE system_key=?');
            $exists->execute([$status[0]]);
            if (!$exists->fetchColumn()) { $insert->execute([...$status, $position, $status[0]]); }
        }
    }
}
