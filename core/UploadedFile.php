<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * A file uploaded with the request.
 *
 *   $path = $request->file('avatar')->store('avatars');  // storage/uploads/avatars/3f9a...e1.jpg
 */
final class UploadedFile
{
    /**
     * Detected MIME type => extension used when storing. Anything not listed is stored as ".bin".
     * SVG is deliberately absent: it can contain <script>, so it must never keep a ".svg" name.
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/bmp' => 'bmp', 'application/pdf' => 'pdf',
        'text/plain' => 'txt', 'text/csv' => 'csv', 'application/json' => 'json',
        'application/zip' => 'zip', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3',
    ];

    private ?string $mime = null;

    /**
     * @param bool $test Skip the is_uploaded_file() check: for tests, and for files the framework wrote
     *                   itself (multipart PUT/PATCH/DELETE on PHP < 8.4, see Request::parseMultipart()).
     */
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly int $error = UPLOAD_ERR_OK,
        public readonly int $size = 0,
        private readonly bool $test = false,
    ) {
    }

    /** True when PHP received the file without errors and it is a genuine upload. */
    public function isValid(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            return false;
        }
        return $this->test ? is_file($this->path) : is_uploaded_file($this->path);
    }

    /**
     * MIME type detected from the file contents (not the client's claim). Without the fileinfo
     * extension (disabled on some shared hosts) every file counts as application/octet-stream,
     * so it is stored as ".bin" and fails image/mimes rules: safe, never guessed from the name.
     */
    public function mimeType(): string
    {
        return $this->mime ??= self::detectMime($this->path);
    }

    /** MIME type of a file from its contents; application/octet-stream when unknown or undetectable. */
    public static function detectMime(string $path): string
    {
        if (!class_exists(\finfo::class)) {
            return 'application/octet-stream';
        }

        return @(new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    }

    /**
     * Extension from the real MIME type (detected from the contents). Types not in EXTENSIONS
     * are saved as "bin": the client's extension is never trusted, so an upload can't be
     * stored as .php, .phtml, .html, .js, ... even if the client names it that way.
     */
    public function extension(): string
    {
        return self::EXTENSIONS[$this->mimeType()] ?? 'bin';
    }

    /** Extension from the original filename (can be faked by the client). */
    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /** Human-readable reason when the upload failed; empty string when OK. */
    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK                           => '',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL                      => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE                      => 'No file was uploaded.',
            default                                 => 'The file could not be uploaded.',
        };
    }

    /** Stores under storage/uploads/{directory} with a random name; returns the relative path. */
    public function store(string $directory = ''): string
    {
        return $this->storeAs($directory, bin2hex(random_bytes(16)) . '.' . $this->extension());
    }

    /** Stores under storage/uploads/{directory}/{name}; returns the relative path. */
    public function storeAs(string $directory, string $name): string
    {
        if (!$this->isValid()) {
            throw new RuntimeException('Cannot store an invalid upload: ' . $this->errorMessage());
        }

        // Backslashes are separators on Windows, so treat them as such everywhere: "..\x.php" must
        // never survive basename() on Linux and then climb out of the folder on a Windows server.
        $directory = trim(str_replace('\\', '/', $directory), '/');
        $name      = basename(str_replace('\\', '/', $name));

        // Windows also ignores trailing dots/spaces ("x.php." is x.php) and treats ":" as an
        // alternate data stream ("x.jpg:y.php"); control characters are never valid.
        $name = rtrim($name, '. ');
        if ($name === '' || $name === '.' || $name === '..'
            || preg_match('/[\x00-\x1F\x7F:]/', $name . $directory)
            || str_contains($directory, '..')) {
            throw new RuntimeException('Invalid storage path.');
        }

        $root   = rtrim((string) Config::get('filesystem.root'), '/\\');
        $target = $root . ($directory === '' ? '' : '/' . $directory);

        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new RuntimeException("Cannot create directory: {$target}");
        }

        $moved = $this->test
            ? rename($this->path, "{$target}/{$name}")
            : move_uploaded_file($this->path, "{$target}/{$name}");

        if (!$moved) {
            throw new RuntimeException('Failed to store the uploaded file.');
        }

        return ltrim("{$directory}/{$name}", '/');
    }
}
