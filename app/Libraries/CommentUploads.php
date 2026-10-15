<?php

namespace App\Libraries;

use InvalidArgumentException;

final class CommentUploads
{
    public static function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) { throw new InvalidArgumentException('Invalid attachment.'); }
        return WRITEPATH . 'uploads/comment-attachments/' . $key;
    }

    public static function receive(array $files): array
    {
        $files = array_values(array_filter($files, fn ($file) => $file->getError() !== UPLOAD_ERR_NO_FILE));
        if (count($files) > 5) { throw new InvalidArgumentException('Attach up to five files.'); }
        $allowed = ['image/png','image/jpeg','image/gif','image/webp','application/pdf','text/plain'];
        $total = 0;
        foreach ($files as $file) {
            if (!$file->isValid() || $file->getSize() > 2 * 1024 * 1024) { throw new InvalidArgumentException('Each attachment must be a valid file under 2 MB.'); }
            $total += $file->getSize();
            if (!in_array($file->getMimeType(), $allowed, true)) { throw new InvalidArgumentException('Use PNG, JPG, GIF, WebP, PDF, or plain text files.'); }
            if (str_starts_with($file->getMimeType(), 'image/') && !@getimagesize($file->getTempName())) { throw new InvalidArgumentException('This image could not be read.'); }
        }
        if ($total > 6 * 1024 * 1024) { throw new InvalidArgumentException('Keep the total attachment size under 6 MB.'); }
        $saved = [];
        try {
            foreach ($files as $file) {
                $key = bin2hex(random_bytes(16));
                $name = mb_substr(preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', $file->getClientName()), 0, 200);
                $metadata = ['storage_key'=>$key,'filename'=>$name ?: 'attachment','mime'=>$file->getMimeType(),'size'=>$file->getSize()];
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
