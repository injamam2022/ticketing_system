<?php

namespace App\Libraries;

use PDO;

final class DiscussionSchema
{
    public static function migrate(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $columns = $mysql ? array_column($db->query('SHOW COLUMNS FROM comments')->fetchAll(PDO::FETCH_ASSOC), 'Field') : array_column($db->query('PRAGMA table_info(comments)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['parent_id' => 'INT NULL', 'edited_at' => 'VARCHAR(32) NULL', 'deleted_at' => 'VARCHAR(32) NULL', 'resolved_at' => 'VARCHAR(32) NULL', 'version' => 'INT NOT NULL DEFAULT 1'] as $name => $type) {
            if (!in_array($name, $columns, true)) { $db->exec("ALTER TABLE comments ADD COLUMN $name $type"); }
        }
        $id = $mysql ? 'INT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $tables = [
            'comment_reactions' => 'comment_id INT NOT NULL, user_id INT NOT NULL, emoji VARCHAR(32) NOT NULL, PRIMARY KEY(comment_id,user_id,emoji), FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id)',
            'comment_subscriptions' => 'comment_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(comment_id,user_id), FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id)',
            'comment_attachments' => "id $id, comment_id INT NOT NULL, storage_key VARCHAR(64) NOT NULL, filename VARCHAR(200) NOT NULL, mime VARCHAR(100) NOT NULL, size INT NOT NULL, FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE",
            'comment_notifications' => "id $id, comment_id INT NOT NULL, user_id INT NOT NULL, kind VARCHAR(20) NOT NULL, created_at VARCHAR(32) NOT NULL, read_at VARCHAR(32) NULL, FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id)",
            'comment_issue_links' => 'comment_id INT NOT NULL, new_issue_id INT NOT NULL UNIQUE, parent_issue_id INT NULL, FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE, FOREIGN KEY(new_issue_id) REFERENCES issues(id) ON DELETE CASCADE, FOREIGN KEY(parent_issue_id) REFERENCES issues(id) ON DELETE CASCADE',
            'issue_events' => "id $id, issue_id INT NOT NULL, user_id INT NULL, body TEXT NOT NULL, created_at VARCHAR(32) NOT NULL, FOREIGN KEY(issue_id) REFERENCES issues(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id)",
        ];
        foreach ($tables as $name => $definition) { $db->exec("CREATE TABLE IF NOT EXISTS $name ($definition)" . $suffix); }
    }
}
