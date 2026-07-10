<?php

namespace App\Rules;

use App\Support\AttachmentContentSniffer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The HTTP-path half of the spec §17.8 content-sniff guard (Phase 4 Task 8)
 * — wraps App\Support\AttachmentContentSniffer so StoreAttachmentRequest can
 * reject a spoofed upload (e.g. a renamed .exe claiming to be a .pdf) with a
 * friendly, field-level 422 before AttachmentService is ever reached.
 */
class AllowedAttachmentContent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute must be a valid uploaded file.');

            return;
        }

        $mime = AttachmentContentSniffer::sniff($value->getRealPath());

        if (! AttachmentContentSniffer::isAllowed($mime)) {
            $fail('The :attribute must be a PDF, JPG, PNG, or WEBP file — checked by its actual content, not its name.');
        }
    }
}
