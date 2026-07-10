<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by AttachmentService::store when an upload fails one of the spec
 * §17.8 hardening guards (oversize, or a content-sniffed MIME type outside
 * the pdf/jpg/png/webp allowlist) — the caller-independent half of the
 * dual-layer guard (App\Rules\AllowedAttachmentContent is the HTTP-path
 * friendly 422 via StoreAttachmentRequest; this is the backstop for any
 * other caller, e.g. a future API/import). Mirrors IneligibleCategoryAccount's
 * shape exactly.
 */
class AttachmentRejected extends DomainException {}
