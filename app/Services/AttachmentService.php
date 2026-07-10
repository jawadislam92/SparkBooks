<?php

namespace App\Services;

use App\Exceptions\AttachmentRejected;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Expense;
use App\Support\AttachmentContentSniffer;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * THE attachment read/write gate (spec §17.8, Phase 4 Task 8) — shared by
 * both ExpenseController and BillController, the same "one service behind
 * two near-identical controllers" shape as CategoryAccountGuard/
 * DocumentLineCalculator. Every hardening rule lives here:
 *
 * - Max 10MB, checked in bytes (the caller-independent backstop —
 *   StoreAttachmentRequest's `max:10240` is the friendly HTTP-path 422).
 * - Content-sniffed allowlist (pdf/jpg/png/webp) via AttachmentContentSniffer
 *   — never the client-declared mime or the filename's extension.
 * - Storage: a uuid-randomized filename (never the client's original name),
 *   under `attachments/{company_id}/`, on the PRIVATE `local` disk (see
 *   config/filesystems.php — NOT `public`).
 * - Every op (upload/delete/download) writes an AuditLogger row.
 */
class AttachmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AttachmentRejected when the file is oversize or its sniffed
     *                            content type isn't on the allowlist.
     */
    public function store(Expense|Bill $attachable, UploadedFile $file): Attachment
    {
        if ($file->getSize() > AttachmentContentSniffer::MAX_BYTES) {
            throw new AttachmentRejected('The file must not be larger than 10MB.');
        }

        // Sniff BEFORE ever looking at the client-declared mime or the
        // original filename's extension — spec §17.8's central rule. A file
        // renamed from .exe to .pdf sniffs as something other than
        // application/pdf and is rejected here regardless of its name.
        $mime = AttachmentContentSniffer::sniff($file->getRealPath());
        $extension = AttachmentContentSniffer::extensionFor($mime);

        if ($extension === null) {
            throw new AttachmentRejected('Unsupported file type. Only PDF, JPG, PNG, and WEBP files are allowed.');
        }

        $filename = Str::uuid()->toString().'.'.$extension;
        $diskPath = $file->storeAs("attachments/{$attachable->company_id}", $filename, 'local');

        if ($diskPath === false) {
            throw new AttachmentRejected('The file could not be stored. Please try again.');
        }

        try {
            return DB::transaction(function () use ($attachable, $diskPath, $file, $mime) {
                /** @var Attachment $attachment */
                $attachment = $attachable->attachments()->create([
                    'disk_path' => $diskPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $mime,
                    'size' => $file->getSize(),
                    'uploaded_by' => auth()->id(),
                ]);

                $this->audit->log('attachment.uploaded', $attachment, after: [
                    'attachable_type' => $attachment->attachable_type,
                    'attachable_id' => $attachment->attachable_id,
                    'original_name' => $attachment->original_name,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                ]);

                return $attachment;
            });
        } catch (Throwable $e) {
            // Defense in depth: never leave an orphan file on disk if the DB
            // write (row create or audit log) failed after the bytes were
            // already stored.
            Storage::disk('local')->delete($diskPath);

            throw $e;
        }
    }

    /**
     * Deletes both the DB row and the underlying file. The row + audit log
     * commit first (inside a transaction); the disk delete happens only
     * after that commit succeeds — a crash between the two leaves an orphan
     * FILE (harmless, cleanable later), never an orphan ROW pointing at
     * bytes that no longer exist.
     */
    public function delete(Attachment $attachment): void
    {
        $before = $attachment->only(['attachable_type', 'attachable_id', 'original_name', 'mime', 'size']);
        $diskPath = $attachment->disk_path;

        DB::transaction(function () use ($attachment, $before) {
            $attachment->delete();
            $this->audit->log('attachment.deleted', $attachment, before: $before);
        });

        Storage::disk('local')->delete($diskPath);
    }

    /**
     * Cascade-cleanup for a document being deleted (draft delete): removes
     * every attachment row now, and the underlying files once the CALLER's
     * transaction commits — must therefore be called from INSIDE that
     * transaction (throws otherwise, same guard shape as LedgerService::
     * post), so a rollback of the document delete can never have already
     * destroyed the bytes. Without this, deleting a draft that had a
     * receipt would orphan the Attachment row (dangling attachable_id) AND
     * leak the private file on disk forever.
     *
     * Deliberately NO per-attachment audit rows here, unlike delete(): the
     * caller's own single 'expense.draft_deleted'/'bill.draft_deleted' row
     * already records the destruction of the whole draft, and a row per
     * receipt would just be noise — mirrors how the document's line rows
     * cascade silently in the same destroy() methods.
     */
    public function deleteAllFor(Expense|Bill $attachable): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('AttachmentService::deleteAllFor must run inside the caller\'s transaction.');
        }

        $attachments = $attachable->attachments()->get();
        if ($attachments->isEmpty()) {
            return;
        }

        $paths = $attachments->pluck('disk_path')->all();
        $attachments->each(fn (Attachment $attachment) => $attachment->delete());

        DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
    }

    /**
     * Streams the attachment's bytes back with its ORIGINAL filename (never
     * the uuid disk name) and its sniffed content-type — the only way an
     * attachment is ever read back (spec §17.8: authenticated, tenant-
     * scoped, gated route only). Logged same as upload/delete — cheap and
     * the spec explicitly calls it out ("log downloads too").
     */
    public function download(Attachment $attachment): StreamedResponse
    {
        $this->audit->log('attachment.downloaded', $attachment);

        return Storage::disk('local')->download(
            $attachment->disk_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime],
        );
    }
}
