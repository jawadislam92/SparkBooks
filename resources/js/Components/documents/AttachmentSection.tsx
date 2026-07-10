import Button from '@/Components/ui/Button';
import { router } from '@inertiajs/react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import { ChangeEvent, useRef, useState } from 'react';

export interface AttachmentData {
    id: number;
    original_name: string;
    mime: string;
    size: number;
    created_at: string | null;
}

interface AttachmentSectionProps {
    attachments: AttachmentData[];
    uploadUrl: string;
    downloadUrl: (attachmentId: number) => string;
    destroyUrl: (attachmentId: number) => string;
    /** expenses.draft/bills.draft — all four roles (spec §17.8: addable anytime, even on a posted document). */
    canUpload: boolean;
    /** expenses.void/bills.void — Owner/Admin/Accountant only (spec §17.8's decision). */
    canDelete: boolean;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

// Short badge label per sniffed content-type (App\Support\AttachmentContentSniffer's
// own allowlist) — deliberately not an icon font/library (none installed);
// a compact text badge in the same design-token palette as the rest of the app.
const TYPE_LABELS: Record<string, string> = {
    'application/pdf': 'PDF',
    'image/jpeg': 'JPG',
    'image/png': 'PNG',
    'image/webp': 'WEBP',
};

function FileTypeBadge({ mime }: { mime: string }) {
    return (
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-spark-50 font-mono text-[10px] font-semibold tracking-tight text-spark-600">
            {TYPE_LABELS[mime] ?? 'FILE'}
        </span>
    );
}

interface DeleteAttachmentDialogProps {
    name: string;
    onCancel: () => void;
    onConfirm: () => void;
    processing: boolean;
}

function DeleteAttachmentDialog({ name, onCancel, onConfirm, processing }: DeleteAttachmentDialogProps) {
    return (
        <Dialog open onClose={onCancel} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-sm rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        Delete this attachment?
                    </DialogTitle>
                    <p className="mt-2 text-sm text-ink-700">
                        &ldquo;{name}&rdquo; will be permanently deleted. This cannot be undone.
                    </p>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="quiet" onClick={onCancel}>
                            Cancel
                        </Button>
                        <Button type="button" variant="danger" onClick={onConfirm} disabled={processing}>
                            {processing ? 'Deleting…' : 'Delete'}
                        </Button>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

/**
 * Receipt/bill-scan attachment list + upload (spec §17.8, Phase 4 Task 8) —
 * shared by Expenses/Edit.tsx and Bills/Edit.tsx (both money-out documents),
 * mirroring how Components/documents/DocumentLineEditor and StatusChip are
 * already shared between the same two pages. Works identically on a draft
 * OR a posted document — attachments are addable anytime (receipts arrive
 * late); only the upload/delete GATES differ per caller via canUpload/
 * canDelete, enforced server-side regardless of what this component shows.
 *
 * Download is a plain `<a href>` (browser navigation), NOT an Inertia
 * `<Link>` — the download route streams a raw file response, not an Inertia
 * page response, so intercepting the click would break it.
 */
export default function AttachmentSection({
    attachments,
    uploadUrl,
    downloadUrl,
    destroyUrl,
    canUpload,
    canDelete,
}: AttachmentSectionProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);

    function pickFile() {
        setUploadError(null);
        fileInputRef.current?.click();
    }

    function onFileSelected(event: ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        // Reset so selecting the SAME file twice in a row still fires onChange.
        event.target.value = '';
        if (!file) {
            return;
        }

        setUploading(true);
        setUploadError(null);
        router.post(
            uploadUrl,
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => {
                    const message = (errors as Record<string, string | undefined>).file;
                    setUploadError(message ?? 'The file could not be uploaded.');
                },
                onFinish: () => setUploading(false),
            },
        );
    }

    const attachmentToDelete = attachments.find((attachment) => attachment.id === confirmingDeleteId) ?? null;

    function confirmDelete() {
        if (!attachmentToDelete) {
            return;
        }
        setDeleting(true);
        router.delete(destroyUrl(attachmentToDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setConfirmingDeleteId(null);
            },
        });
    }

    return (
        <div>
            <div className="flex items-center justify-between">
                <h3 className="font-display text-sm font-semibold text-ink">Attachments</h3>
                {canUpload && (
                    <>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            className="hidden"
                            onChange={onFileSelected}
                        />
                        <Button type="button" variant="quiet" size="sm" onClick={pickFile} disabled={uploading}>
                            {uploading ? 'Uploading…' : 'Upload receipt'}
                        </Button>
                    </>
                )}
            </div>

            {uploadError && (
                <p role="alert" className="mt-2 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                    {uploadError}
                </p>
            )}

            {attachments.length === 0 ? (
                <p className="mt-3 text-sm text-ink-700">No attachments yet.</p>
            ) : (
                <ul className="mt-3 divide-y divide-rule">
                    {attachments.map((attachment) => (
                        <li key={attachment.id} className="flex items-center gap-3 py-3">
                            <FileTypeBadge mime={attachment.mime} />
                            <div className="min-w-0 flex-1">
                                <a
                                    href={downloadUrl(attachment.id)}
                                    className="block truncate text-sm font-medium text-ink transition-colors duration-[120ms] ease-out hover:text-lagoon"
                                >
                                    {attachment.original_name}
                                </a>
                                <p className="text-xs text-ink-700">{formatBytes(attachment.size)}</p>
                            </div>
                            {canDelete && (
                                <Button
                                    type="button"
                                    variant="quiet"
                                    size="sm"
                                    onClick={() => setConfirmingDeleteId(attachment.id)}
                                >
                                    Delete
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {attachmentToDelete && (
                <DeleteAttachmentDialog
                    name={attachmentToDelete.original_name}
                    onCancel={() => setConfirmingDeleteId(null)}
                    onConfirm={confirmDelete}
                    processing={deleting}
                />
            )}
        </div>
    );
}
