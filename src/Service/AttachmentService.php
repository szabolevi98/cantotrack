<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Logger;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\AttachmentRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * Files on tickets: taking them in, handing them out, and throwing them away.
 *
 * Every upload is a file somebody else will open, so what is accepted is
 * decided by what the file is — read from its bytes — and not by its name or
 * by the type the browser sent, both of which the uploader chooses. Only the
 * common kinds are taken: pictures, documents, logs, archives, short videos.
 * An HTML page or an SVG is not a screenshot, and served from this domain it
 * would run as this application.
 */
class AttachmentService
{
    /** What is accepted, by the type found in the file, and the extension it is stored under. */
    public const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json',
        'application/zip' => 'zip',
        'application/gzip' => 'gz',
        'application/x-gzip' => 'gz',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    ];

    /**
     * The pictures a browser may show in place. Everything else is handed out
     * as a download: a PDF or a text file opened inside this origin is a page
     * of this origin.
     */
    public const INLINE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Office files are zip archives inside; the extension says which kind. */
    private const OFFICE = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    private AttachmentRepository $attachments;
    private TicketRepository $tickets;
    private Activity $activity;

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();

        $this->attachments = new AttachmentRepository($db);
        $this->tickets = new TicketRepository($db);
        $this->activity = new Activity($db);
    }

    public static function maxBytes(): int
    {
        return max(1, Config::int('uploads.max_mb', 10)) * 1024 * 1024;
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/var/uploads';
    }

    /**
     * Takes one uploaded file — an entry of $_FILES — onto a ticket.
     *
     * @param array<string, mixed> $upload
     * @throws ValidationError
     */
    public function store(int $ticketId, int $userId, array $upload): array
    {
        $ticket = $this->tickets->find($ticketId);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string) ($upload['tmp_name'] ?? '');
        $name = self::cleanName((string) ($upload['name'] ?? 'file'));

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($upload['size'] ?? 0) > self::maxBytes()) {
            throw new ValidationError(__('{name} is too big: the limit is {mb} MB.', ['name' => $name, 'mb' => intdiv(self::maxBytes(), 1048576)]));
        }

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            throw new ValidationError(__('{name} did not arrive. Try again.', ['name' => $name]));
        }

        $mime = $this->detect($tmp, $name);

        if ($mime === null) {
            throw new ValidationError(__('{name} is not a kind of file that can be attached here.', ['name' => $name]));
        }

        $relative = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $target = self::directory() . '/' . $relative;

        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            Logger::error('The upload folder could not be made: ' . dirname($target));
            throw new ValidationError(__('The file could not be stored. It has been written to the log.'));
        }

        if (!move_uploaded_file($tmp, $target)) {
            Logger::error('An upload could not be moved into ' . $target);
            throw new ValidationError(__('The file could not be stored. It has been written to the log.'));
        }

        $size = (int) filesize($target);
        $dimensions = in_array($mime, self::INLINE, true) ? @getimagesize($target) : false;

        $id = $this->attachments->create([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'original_name' => $name,
            'stored_path' => $relative,
            'mime' => $mime,
            'size' => $size,
            'width' => $dimensions === false ? null : min(65535, (int) $dimensions[0]),
            'height' => $dimensions === false ? null : min(65535, (int) $dimensions[1]),
        ]);

        $this->activity->happened($ticket, $userId, 'attached', 'attachment', null, $name);

        return (array) $this->attachments->find($id);
    }

    public function remove(array $attachment, ?int $actorId): void
    {
        $this->attachments->delete((int) $attachment['id']);
        self::unlink((string) $attachment['stored_path']);

        $ticket = $this->tickets->find((int) $attachment['ticket_id']);
        if ($ticket !== null) {
            $this->activity->happened($ticket, $actorId, 'detached', 'attachment', (string) $attachment['original_name']);
        }
    }

    public static function canRemove(array $attachment, int $userId, bool $isAdmin): bool
    {
        return $isAdmin || (int) $attachment['user_id'] === $userId;
    }

    /** The file on disk, if it is really inside the upload folder. */
    public static function pathOf(array $attachment): ?string
    {
        $base = realpath(self::directory());
        $path = realpath(self::directory() . '/' . $attachment['stored_path']);

        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    /** @param list<string> $paths */
    public static function unlinkAll(array $paths): void
    {
        foreach ($paths as $path) {
            self::unlink($path);
        }
    }

    private static function unlink(string $relative): void
    {
        $path = self::pathOf(['stored_path' => $relative]);

        if ($path !== null) {
            @unlink($path);
        }
    }

    /** The type the file's own bytes say it is, if it is one that is taken. */
    private function detect(string $file, string $name): ?string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // An Office document is a zip; finfo sometimes says only that.
        if (in_array($mime, ['application/zip', 'application/octet-stream'], true) && isset(self::OFFICE[$extension])) {
            return self::OFFICE[$extension];
        }

        // A log file or a Markdown note is plain text, whatever it is called.
        if (str_starts_with($mime, 'text/') && !in_array($mime, ['text/html', 'text/xml'], true)) {
            return $mime === 'text/csv' ? 'text/csv' : 'text/plain';
        }

        return array_key_exists($mime, self::TYPES) ? $mime : null;
    }

    /**
     * The name to show and to download under: without a path, without
     * control characters, and not so long that it breaks a layout.
     */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);
        $name = trim($name, " .\t");

        return mb_substr($name === '' ? 'file' : $name, 0, 200);
    }
}
