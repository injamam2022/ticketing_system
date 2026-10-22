<?php

namespace App\Libraries;

use PDO;
use RuntimeException;

final class SqliteToMysql
{
    public const TABLES = ['users', 'institutes', 'projects', 'clients', 'issues', 'comments', 'client_projects', 'client_messages', 'login_attempts', 'comment_reactions', 'comment_subscriptions', 'comment_attachments', 'comment_notifications', 'comment_issue_links', 'issue_events', 'workspace_statuses', 'ticket_images'];

    /** Import into an empty schema, retain identifiers, and verify every field before commit. */
    public static function import(PDO $source, PDO $target): array
    {
        foreach (self::TABLES as $table) {
            if ((int) $target->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 0) {
                throw new RuntimeException('Import refused: the destination already contains data in ' . $table . '.');
            }
        }
        if ($source->query('PRAGMA foreign_key_check')->fetchAll()) { throw new RuntimeException('The source contains invalid foreign keys. No data was imported.'); }
        $target->beginTransaction();
        $counts = [];
        try {
            foreach (self::TABLES as $table) {
                $exists = $source->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
                $exists->execute([$table]);
                if (!$exists->fetchColumn()) { $counts[$table] = 0; continue; }
                $rows = $source->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                $counts[$table] = count($rows);
                if (!$rows) { continue; }
                $columns = array_keys($rows[0]);
                $quoted = array_map(fn ($column) => '`' . str_replace('`', '``', $column) . '`', $columns);
                $insert = $target->prepare("INSERT INTO `$table` (" . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
                foreach ($rows as $row) { $insert->execute(array_values($row)); }
                $actual = $target->query('SELECT ' . implode(',', $quoted) . " FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                // PDO numeric types can differ by driver; normalize without changing NULL or text.
                $fingerprint = static function (array $records): array {
                    $hashes = array_map(static fn ($record) => hash('sha256', json_encode(array_map(static fn ($value) => $value === null ? null : (string) $value, $record), JSON_THROW_ON_ERROR)), $records);
                    sort($hashes);
                    return $hashes;
                };
                if ($fingerprint($rows) !== $fingerprint($actual)) { throw new RuntimeException('Verification failed for ' . $table . '. Import rolled back.'); }
            }
            $target->commit();
        } catch (\Throwable $error) { if ($target->inTransaction()) { $target->rollBack(); } throw $error; }

        // Keep SQLite's sequence high-water marks, including IDs of deleted issues.
        foreach ($source->query('SELECT name,seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_ASSOC) as $sequence) {
            if (in_array($sequence['name'], ['institutes', 'projects', 'clients', 'issues', 'comments', 'client_messages', 'comment_attachments', 'comment_notifications', 'issue_events', 'ticket_images'], true)) {
                $target->exec('ALTER TABLE `' . $sequence['name'] . '` AUTO_INCREMENT = ' . ((int) $sequence['seq'] + 1));
            }
        }
        return $counts;
    }
}
