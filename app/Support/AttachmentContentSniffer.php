<?php

namespace App\Support;

/**
 * THE server-side content sniff for attachment uploads (spec §17.8, Phase 4
 * Task 8) — determines a file's REAL type from its bytes via finfo, never
 * trusting the client-declared Content-Type or the filename's extension. A
 * file renamed from .exe to .pdf is rejected here regardless of what the
 * browser claimed it was.
 *
 * Both App\Rules\AllowedAttachmentContent (the friendly HTTP-path 422, via
 * StoreAttachmentRequest) and AttachmentService::store (the caller-
 * independent guard — mirrors CategoryAccountGuard's dual-layer shape, Phase
 * 4 T2) call into this ONE allowlist so the two can never drift apart.
 *
 * Static/stateless by design, like CategoryAccountGuard/DocumentLineCalculator.
 */
class AttachmentContentSniffer
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string, string> sniffed MIME type => safe stored extension */
    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Reads the file's magic bytes via ext-fileinfo (never the filename or
     * any client-supplied header) and returns the detected MIME type, or
     * null if it can't be determined at all.
     */
    public static function sniff(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
    }

    public static function isAllowed(?string $mime): bool
    {
        return $mime !== null && isset(self::ALLOWED[$mime]);
    }

    /** The safe extension to store the file under for a given SNIFFED mime, or null if not allowed. */
    public static function extensionFor(?string $mime): ?string
    {
        return $mime !== null ? (self::ALLOWED[$mime] ?? null) : null;
    }
}
