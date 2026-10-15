<?php

namespace App\Libraries;

use InvalidArgumentException;
use PDO;

final class TicketImages
{
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public static function schema(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS ticket_images (id $id, issue_id INT NOT NULL, storage_key VARCHAR(32) NOT NULL UNIQUE, filename VARCHAR(200) NOT NULL, mime VARCHAR(30) NOT NULL, size INT NOT NULL, FOREIGN KEY(issue_id) REFERENCES issues(id) ON DELETE CASCADE)" . $suffix);
    }

    public static function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) { throw new InvalidArgumentException('Invalid image.'); }
        return WRITEPATH . 'uploads/ticket-images/' . $key;
    }

    public static function receive(array $files): array
    {
        $files = array_values(array_filter($files, fn ($file) => $file->getError() !== UPLOAD_ERR_NO_FILE));
        if (count($files) > 5) { throw new InvalidArgumentException('Attach up to 5 images per ticket.'); }
        $total = 0;
        foreach ($files as $file) {
            if (!$file->isValid() || $file->getSize() > 2 * 1024 * 1024) { throw new InvalidArgumentException('Each image must be a valid file up to 2 MB.'); }
            $total += $file->getSize();
            $info = @getimagesize($file->getTempName());
            if (!in_array($file->getMimeType(), self::MIME_TYPES, true) || !$info || !in_array($info['mime'] ?? '', self::MIME_TYPES, true)) { throw new InvalidArgumentException('Use PNG, JPG, GIF, or WebP images.'); }
        }
        if ($total > 6 * 1024 * 1024) { throw new InvalidArgumentException('Keep the total image size up to 6 MB.'); }
        $saved = [];
        try {
            foreach ($files as $file) {
                $key = bin2hex(random_bytes(16));
                $name = mb_substr(preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', $file->getClientName()) ?? 'image', 0, 200);
                $metadata = ['storage_key' => $key, 'filename' => $name ?: 'image', 'mime' => $file->getMimeType(), 'size' => $file->getSize()];
                $file->move(dirname(self::path($key)), $key);
                $saved[] = $metadata;
            }
        } catch (\Throwable $error) { self::discard($saved); throw $error; }
        return $saved;
    }

    public static function discard(array $files): void
    {
        foreach ($files as $file) { $path = self::path($file['storage_key']); if (is_file($path)) { unlink($path); } }
    }
}
