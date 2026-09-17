<?php

declare(strict_types=1);

namespace EasyBusyConnect\Support;

use EasyBusyConnect\Settings;

/**
 * Private staging area for patient attachments (panoramic X-rays and similar).
 *
 * Files are medical images: they land outside the media library, in a directory
 * that denies direct access, under randomised names, and they are deleted as
 * soon as EasyBusy has accepted them — or by the daily purge if a submission was
 * abandoned.
 */
final class Uploads
{
    private const DIR = 'easybusy-connect/private';
    private const ALLOWED = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];

    /**
     * Staging lives **outside the document root** when the host allows it.
     * Measured on this host: nginx serves static files directly and ignores the
     * `.htaccess` guard, so a file under wp-content/uploads was fetchable over
     * HTTP (200). Patient X-rays must never be publicly readable, so the parent
     * of ABSPATH is preferred and wp-content/uploads is only a fallback.
     */
    public static function dir(): string
    {
        $outside = dirname(untrailingslashit(ABSPATH)) . '/ebc-private';
        if (is_dir($outside) || wp_mkdir_p($outside)) {
            if (is_writable($outside)) {
                return $outside;
            }
        }

        $base = wp_get_upload_dir();

        return rtrim((string) $base['basedir'], '/\\') . '/' . self::DIR;
    }

    public static function isOutsideDocroot(): bool
    {
        return !str_contains(self::dir(), 'uploads');
    }

    public static function ensure(): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        // Belt and braces for the fallback location (Apache only; nginx ignores it).
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        return true;
    }

    public static function maxBytes(): int
    {
        return max(1, (int) Settings::get('attachment_max_mb', 10)) * 1024 * 1024;
    }

    public static function maxFiles(): int
    {
        return max(1, (int) Settings::get('attachment_max_files', 3));
    }

    /**
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file
     * @return array{path:string,name:string,size:int,type:string}|\WP_Error
     */
    public static function store(array $file): array|\WP_Error
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name'])) {
            return new \WP_Error('ebc_upload_failed', __('The file could not be uploaded.', 'easybusy-connect'), ['status' => 400]);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::maxBytes()) {
            return new \WP_Error('ebc_upload_too_large', sprintf(
                /* translators: %d: size in megabytes */
                __('Each file must be smaller than %d MB.', 'easybusy-connect'),
                (int) Settings::get('attachment_max_mb', 10)
            ), ['status' => 413]);
        }

        $name = (string) ($file['name'] ?? 'upload');
        // Trust the sniffed type, not the extension the browser sent.
        $checked = wp_check_filetype_and_ext((string) $file['tmp_name'], $name, self::ALLOWED);
        $ext = strtolower((string) ($checked['ext'] ?? ''));
        if ($ext === '' || !isset(self::ALLOWED[$ext])) {
            return new \WP_Error('ebc_upload_type', __('Only JPG, PNG and PDF files are accepted.', 'easybusy-connect'), ['status' => 415]);
        }

        if (!self::ensure()) {
            return new \WP_Error('ebc_upload_dir', __('The upload directory is not writable.', 'easybusy-connect'), ['status' => 500]);
        }

        $target = self::dir() . '/' . gmdate('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $target)
            : rename((string) $file['tmp_name'], $target);

        if (!$moved) {
            return new \WP_Error('ebc_upload_move', __('The file could not be stored.', 'easybusy-connect'), ['status' => 500]);
        }

        return [
            'path' => $target,
            'name' => sanitize_file_name($name),
            'size' => $size,
            'type' => (string) self::ALLOWED[$ext],
        ];
    }

    public static function delete(string $path): void
    {
        if ($path !== '' && str_starts_with($path, self::dir()) && is_file($path)) {
            @unlink($path);
        }
    }

    /** Abandoned staging files must not linger; called by the daily cron. */
    public static function purgeOlderThan(int $hours = 24): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return 0;
        }

        $cutoff = time() - ($hours * HOUR_IN_SECONDS);
        $deleted = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            $basename = basename($file);
            if ($basename === '.htaccess' || $basename === 'index.php' || !is_file($file)) {
                continue;
            }
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
