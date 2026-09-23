<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use PDO;

/**
 * Profile pictures: cut square, made small, and stored as a new file each
 * time — the name changes with the picture, so a browser can keep one for a
 * year and still show the new one the moment it is changed.
 *
 * The upload is decoded and drawn again rather than stored as sent: what is
 * kept is pixels this code made, not whatever else the file carried along
 * (the camera's location in its EXIF, or a script dressed up as a GIF).
 */
class Avatars
{
    public const SIZE = 256;
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<int, string>|null user id => file, read once per request */
    private static ?array $all = null;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/var/uploads/avatars';
    }

    /** The address of somebody's picture, or null for none (the initials, then). */
    public static function url(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        if (self::$all === null) {
            self::$all = [];
            $statement = DatabaseConnection::get()->prepare('SELECT id, avatar FROM users WHERE avatar IS NOT NULL');
            $statement->execute();

            foreach ($statement->fetchAll() as $row) {
                self::$all[(int) $row['id']] = (string) $row['avatar'];
            }
        }

        return isset(self::$all[$userId]) ? Presenter::url('/avatars/' . $userId . '/' . self::$all[$userId]) : null;
    }

    /**
     * Takes an uploaded picture, and returns the stored file's name.
     *
     * @throws ValidationError when it is not a picture, or too big
     */
    public function store(int $userId, string $uploaded): string
    {
        if (!is_file($uploaded) || filesize($uploaded) > self::MAX_BYTES) {
            throw new ValidationError(__('A picture of at most 5 MB, please.'));
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($uploaded);

        if (!in_array($type, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            throw new ValidationError(__('That is not a picture this can read: PNG, JPEG, WebP or GIF.'));
        }

        $source = @imagecreatefromstring((string) file_get_contents($uploaded));

        if ($source === false) {
            throw new ValidationError(__('That picture could not be read.'));
        }

        // The middle square of it, drawn at the size it is shown at (twice,
        // for sharp screens).
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, (int) imagecolorallocatealpha($square, 0, 0, 0, 127));
        imagecopyresampled($square, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        if (!is_dir(self::directory())) {
            mkdir(self::directory(), 0775, true);
        }

        $webp = function_exists('imagewebp');
        $file = $userId . '-' . bin2hex(random_bytes(6)) . ($webp ? '.webp' : '.png');
        $ok = $webp ? imagewebp($square, self::directory() . '/' . $file, 85) : imagepng($square, self::directory() . '/' . $file, 6);

        if (!$ok) {
            throw new ValidationError(__('The picture could not be saved.'));
        }

        $this->remove($userId);
        $this->db->prepare('UPDATE users SET avatar = :file WHERE id = :id')->execute(['file' => $file, 'id' => $userId]);
        self::$all = null;

        return $file;
    }

    public function remove(int $userId): void
    {
        $statement = $this->db->prepare('SELECT avatar FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        $old = $statement->fetchColumn();

        if (is_string($old) && $old !== '' && basename($old) === $old) {
            @unlink(self::directory() . '/' . $old);
        }

        $this->db->prepare('UPDATE users SET avatar = NULL WHERE id = :id')->execute(['id' => $userId]);
        self::$all = null;
    }
}
