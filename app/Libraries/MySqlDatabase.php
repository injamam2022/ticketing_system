<?php

namespace App\Libraries;

use PDO;
use InvalidArgumentException;

final class MySqlDatabase
{
    private static function setting(string $key, mixed $default): mixed
    {
        // Honor inherited process settings even when PHP's variables_order omits E.
        $value = getenv($key);
        return $value === false ? env($key, $default) : $value;
    }

    public static function name(): string
    {
        $name = (string) self::setting('database.default.database', 'orbit_ticketing');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) { throw new InvalidArgumentException('Use letters, numbers, and underscores in the database name.'); }
        return $name;
    }

    public static function connect(bool $serverOnly = false): PDO
    {
        $username = (string) self::setting('database.default.username', 'root');
        $password = (string) self::setting('database.default.password', '');
        if (ENVIRONMENT === 'production' && ($username === 'root' || $password === '')) {
            throw new InvalidArgumentException('Production requires a dedicated MySQL user with a non-empty password. Update database.default.username and database.default.password.');
        }
        $host = (string) self::setting('database.default.hostname', '127.0.0.1');
        $port = (int) self::setting('database.default.port', 3306);
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
        if (!$serverOnly) { $dsn .= ';dbname=' . self::name(); }
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public static function schema(PDO $db): void
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $tables = [
            'users' => 'id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, color VARCHAR(7) NOT NULL',
            'projects' => 'id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(80) NOT NULL, description TEXT NOT NULL, color VARCHAR(7) NOT NULL',
            'clients' => 'id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100) NOT NULL, company VARCHAR(100) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, active TINYINT NOT NULL DEFAULT 1',
            'issues' => 'id INT PRIMARY KEY AUTO_INCREMENT, title VARCHAR(200) NOT NULL, description MEDIUMTEXT NOT NULL, status VARCHAR(30) NOT NULL, priority VARCHAR(30) NOT NULL, assignee_id INT NULL, project_id INT NULL, label VARCHAR(40) NOT NULL DEFAULT \'\', due_date DATE NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, client_id INT NULL,
                FOREIGN KEY (assignee_id) REFERENCES users(id), FOREIGN KEY (project_id) REFERENCES projects(id), FOREIGN KEY (client_id) REFERENCES clients(id)',
            'comments' => 'id INT PRIMARY KEY AUTO_INCREMENT, issue_id INT NOT NULL, user_id INT NOT NULL, body TEXT NOT NULL, created_at VARCHAR(32) NOT NULL,
                FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id)',
            'client_projects' => 'client_id INT NOT NULL, project_id INT NOT NULL, PRIMARY KEY (client_id, project_id),
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE, FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE',
            'client_messages' => 'id INT PRIMARY KEY AUTO_INCREMENT, issue_id INT NOT NULL, client_id INT NULL, staff_id INT NULL, body TEXT NOT NULL, created_at VARCHAR(32) NOT NULL,
                FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE, FOREIGN KEY (client_id) REFERENCES clients(id), FOREIGN KEY (staff_id) REFERENCES users(id), CHECK ((client_id IS NULL) != (staff_id IS NULL))',
            'login_attempts' => 'fingerprint VARCHAR(64) PRIMARY KEY, attempts INT NOT NULL, started BIGINT NOT NULL',
        ];
        foreach ($tables as $name => $columns) { $db->exec('CREATE TABLE IF NOT EXISTS `' . $name . '` (' . $columns . ')' . $suffix); }
        DiscussionSchema::migrate($db);
        StatusSchema::create($db);
        TicketImages::schema($db);
        InstituteSchema::migrate($db);
    }
}
