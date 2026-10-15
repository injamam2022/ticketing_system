<?php

namespace App\Commands;

use App\Libraries\MySqlDatabase;
use App\Libraries\SqliteToMysql;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use PDO;
use RuntimeException;

class MigrateMysql extends BaseCommand
{
    protected $group = 'Workspace';
    protected $name = 'workspace:migrate-mysql';
    protected $description = 'Back up SQLite, import into an empty MySQL database, verify all data, then activate MySQL.';

    public function run(array $params)
    {
        $lockPath = WRITEPATH . 'database-migration.lock';
        $lock = @fopen($lockPath, 'x');
        if (!$lock) { CLI::error('A database migration is already in progress.'); return EXIT_ERROR; }
        try {
            $path = (string) env('workspace.databasePath', WRITEPATH . 'workspace.sqlite');
            if (!is_file($path)) { throw new RuntimeException('SQLite source not found.'); }
            if (env('workspace.databaseDriver', 'sqlite') === 'mysql') { throw new RuntimeException('MySQL is already active. Import refused.'); }
            $source = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $source->exec('PRAGMA busy_timeout=10000');
            $backup = WRITEPATH . 'workspace-before-mysql-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
            $source->exec('VACUUM INTO ' . $source->quote($backup));
            $snapshot = new PDO('sqlite:' . $backup, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $name = MySqlDatabase::name();
            $server = MySqlDatabase::connect(true);
            $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $target = MySqlDatabase::connect();
            $existingTables = $target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff($existingTables, SqliteToMysql::TABLES)) { throw new RuntimeException('Destination has unrelated tables. Choose an empty database.'); }
            MySqlDatabase::schema($target);
            $counts = SqliteToMysql::import($snapshot, $target);
            $envPath = ROOTPATH . '.env';
            $environment = file_get_contents($envPath);
            if ($environment === false) { throw new RuntimeException('Cannot read .env. Import succeeded but MySQL was not activated.'); }
            $updated = preg_replace('/^workspace\.databaseDriver\s*=.*$/m', "workspace.databaseDriver = mysql", $environment, -1, $replaced);
            if (!$replaced) { $updated .= "\nworkspace.databaseDriver = mysql\n"; }
            if (file_put_contents($envPath, $updated, LOCK_EX) === false) { throw new RuntimeException('Import succeeded but .env could not be updated.'); }
            CLI::write('MySQL database activated: ' . $name, 'green');
            foreach ($counts as $table => $count) { CLI::write($table . ': ' . $count . ' rows verified'); }
            CLI::write('SQLite backup: ' . $backup);
            CLI::write('The original SQLite file is also retained.');
            return EXIT_SUCCESS;
        } catch (\Throwable $error) {
            CLI::error($error->getMessage());
            return EXIT_ERROR;
        } finally {
            fclose($lock);
            unlink($lockPath);
        }
    }
}
