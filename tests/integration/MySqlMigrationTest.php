<?php

use App\Libraries\MySqlDatabase;
use App\Libraries\SqliteToMysql;
use App\Libraries\WorkspaceStore;
use CodeIgniter\Test\CIUnitTestCase;

final class MySqlMigrationTest extends CIUnitTestCase
{
    public function testMysqlImportPreservesRelationshipsAndSupportsWrites(): void
    {
        if (getenv('ORBIT_TEST_MYSQL') !== '1') { $this->markTestSkipped('Set ORBIT_TEST_MYSQL=1 to use a temporary local MySQL test database.'); }
        $name = 'orbit_migration_test_' . bin2hex(random_bytes(6));
        $previous = getenv('database.default.database');
        $path = tempnam(sys_get_temp_dir(), 'orbit-mysql-test-');
        $server = MySqlDatabase::connect(true);
        try {
            $sqlite = new WorkspaceStore($path);
            $statusId = $sqlite->saveStatus(['name' => 'Client Approval', 'color' => '#00aabb', 'category' => 'review']);
            $client = $sqlite->saveClient(['name' => 'Client Test', 'company' => 'Example', 'email' => 'migration@example.com', 'password' => 'Password-for-test-123', 'projects' => [1], 'active' => '1']);
            $id = $sqlite->submitClientTicket($client, ['title' => 'Unicode request: café 🚀', 'description' => "Client's request\nSecond line", 'project_id' => 1], [['storage_key' => str_repeat('c', 32), 'filename' => 'Client screenshot.png', 'mime' => 'image/png', 'size' => 100]]);
            $sqlite->addComment($id, 1, 'Private note');
            $sqlite->addClientMessage($id, 'Public message', $client);
            $deleted = $sqlite->saveIssue(['title' => 'Deleted before migration']);
            $sqlite->deleteIssue($deleted);
            $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            putenv('database.default.database=' . $name);
            $target = MySqlDatabase::connect();
            MySqlDatabase::schema($target);
            $source = new PDO('sqlite:' . $path);
            $counts = SqliteToMysql::import($source, $target);
            $this->assertSame(18, $counts['issues']);
            $mysql = new WorkspaceStore($target);
            $this->assertSame(1, $counts['ticket_images']);
            $this->assertSame('Client screenshot.png', $mysql->ticketImages($id, $client)[0]['filename']);
            $this->assertSame('Client Approval', $mysql->statuses()[5]['name']);
            $mysql->saveIssue(['status' => 'Client Approval'], $id);
            $mysql->saveStatus(['name' => 'Approved', 'color' => '#11aa77', 'category' => 'done'], $statusId);
            $this->assertSame('Approved', $mysql->clientTicket($client, $id)['status']);
            $this->assertSame('Unicode request: café 🚀', $mysql->clientTicket($client, $id)['title']);
            $this->assertSame('Public message', $mysql->clientMessages($id)[0]['body']);
            $this->assertSame('Private note', $mysql->comments($id)[0]['body']);
            $this->assertTrue(password_verify('Password-for-test-123', $mysql->clientByEmail('migration@example.com')['password']));
            $this->assertGreaterThan($deleted, $mysql->saveIssue(['title' => 'First new MySQL ticket']));
            $mysql->recordLogin('test-fingerprint', false);
            $mysql->recordLogin('test-fingerprint', false);
            $this->assertSame(2, (int) $target->query("SELECT attempts FROM login_attempts WHERE fingerprint='test-fingerprint'")->fetchColumn());
            $mysql->saveIssue(['status' => 'Done'], $id);
            $this->assertSame('Done', $mysql->clientTicket($client, $id)['status']);
            try { SqliteToMysql::import($source, $target); $this->fail('Nonempty destination was overwritten'); }
            catch (RuntimeException $error) { $this->assertStringContainsString('already contains data', $error->getMessage()); unset($error); }
            $mysql->deleteIssue($id);
            $this->assertSame([], $mysql->clientMessages($id));
            $this->assertSame([], $mysql->comments($id));
        } finally {
            // This test creates and owns only this randomly named database.
            $server->exec("DROP DATABASE IF EXISTS `$name`");
            putenv($previous === false ? 'database.default.database' : 'database.default.database=' . $previous);
            $source = $sqlite = $target = $mysql = null;
            unlink($path);
        }
    }
}
