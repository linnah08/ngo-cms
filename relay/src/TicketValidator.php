<?php
declare(strict_types=1);

namespace SupportRelay;

final class ValidationException extends \RuntimeException {}

/**
 * Validates the multipart fields of a ticket. Throws ValidationException (→ 400 "invalid").
 * Messages are for the server log only; the client always just sees "invalid".
 */
final class TicketValidator
{
    public const MAX_SUBJECT     = 150;
    public const MAX_DESCRIPTION = 5000;
    public const MAX_PAGE        = 300;
    public const MAX_DIAGNOSTICS = 20000;
    public const MAX_SCREENSHOT  = 5 * 1024 * 1024;

    public const ALLOWED_IMAGE_TYPES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** @param bool $requireUploadedFile false only in tests (is_uploaded_file() is always false under CLI) */
    public function __construct(private readonly bool $requireUploadedFile = true) {}

    /**
     * @return array{subject:string, description:string, page:string, diagnostics:?array,
     *               screenshot:?array{path:string, mime:string, ext:string, size:int}}
     */
    public function validate(array $post, array $files): array
    {
        $subject     = $this->text($post, 'subject', self::MAX_SUBJECT, true);
        $description = $this->text($post, 'description', self::MAX_DESCRIPTION, true);
        $page        = $this->text($post, 'page', self::MAX_PAGE, false);

        $diagnostics = null;
        $rawDiag = $this->text($post, 'diagnostics', self::MAX_DIAGNOSTICS, false, false);
        if ($rawDiag !== '') {
            $decoded = json_decode($rawDiag, true, 32);
            if (!is_array($decoded)) {
                throw new ValidationException('diagnostics is not a JSON object/array');
            }
            $diagnostics = $decoded;
        }

        return [
            'subject'     => self::singleLine($subject),
            'description' => self::stripControl($description),
            'page'        => self::singleLine($page),
            'diagnostics' => $diagnostics,
            'screenshot'  => $this->screenshot($files),
        ];
    }

    private function text(array $post, string $key, int $max, bool $required, bool $trim = true): string
    {
        $v = $post[$key] ?? '';
        if (!is_string($v)) {
            throw new ValidationException("$key is not a string");
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            throw new ValidationException("$key is not valid UTF-8");
        }
        if ($trim) {
            $v = trim($v);
        }
        if ($required && $v === '') {
            throw new ValidationException("$key is required");
        }
        if (mb_strlen($v) > $max) {
            throw new ValidationException("$key exceeds $max chars");
        }
        return $v;
    }

    private function screenshot(array $files): ?array
    {
        if (!isset($files['screenshot'])) {
            return null;
        }
        $f = $files['screenshot'];
        if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
            throw new ValidationException('screenshot malformed');       // e.g. screenshot[]=...
        }
        if ((int) $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) $f['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('screenshot upload error ' . (int) $f['error']);
        }
        $path = (string) ($f['tmp_name'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new ValidationException('screenshot tmp file missing');
        }
        if ($this->requireUploadedFile && !is_uploaded_file($path)) {
            throw new ValidationException('screenshot is not an uploaded file');
        }
        $size = filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_SCREENSHOT) {
            throw new ValidationException('screenshot size out of range');
        }
        // Real content sniffing — the client-supplied MIME type and filename are ignored.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !isset(self::ALLOWED_IMAGE_TYPES[$mime])) {
            throw new ValidationException('screenshot type not allowed: ' . (is_string($mime) ? $mime : '?'));
        }
        return ['path' => $path, 'mime' => $mime, 'ext' => self::ALLOWED_IMAGE_TYPES[$mime], 'size' => $size];
    }

    /** Remove C0 controls (except \n and \t), DEL, and normalise newlines. */
    public static function stripControl(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    }

    /** Collapse to one line: controls stripped, all whitespace runs → single space. */
    public static function singleLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::stripControl($s)));
    }
}
