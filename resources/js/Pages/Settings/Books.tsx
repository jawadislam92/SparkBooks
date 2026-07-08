import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useState } from 'react';

interface BooksPageProps extends PageProps {
    lockedThrough: string | null;
    canManage: boolean;
}

interface LockFormData {
    locked_through: string;
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

type ConfirmKind = 'lock' | 'unlock' | null;

/**
 * Settings > Books: shows the current period lock and, for Owner/Accountant
 * (canManage), lets them move or clear it. Admin (reports.view only) sees
 * the same page read-only. Both actions are confirmed before submitting —
 * a period lock affects what everyone else on the team can post.
 */
export default function Books({ lockedThrough, canManage }: BooksPageProps) {
    const form = useForm<LockFormData>({
        locked_through: lockedThrough ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [confirmKind, setConfirmKind] = useState<ConfirmKind>(null);

    const requestLock: FormEventHandler = (event) => {
        event.preventDefault();
        if (!form.data.locked_through || form.processing) {
            return;
        }
        setConfirmKind('lock');
    };

    const requestUnlock = () => {
        if (form.processing) {
            return;
        }
        form.setData('locked_through', '');
        setConfirmKind('unlock');
    };

    const closeConfirm = () => setConfirmKind(null);

    const confirmSubmit = () => {
        form.post(route('settings.books.update'), {
            preserveScroll: true,
            onSuccess: closeConfirm,
            onError: closeConfirm,
        });
    };

    return (
        <AppShell title="Books & period lock">
            <Head title="Books & period lock" />

            <Sheet title="Period lock">
                <div className="flex flex-col gap-6">
                    <p className="text-sm text-ink-700">
                        Lock closed periods so past entries can&rsquo;t be
                        changed. Entries dated on or before the lock date
                        can&rsquo;t be added, edited, or reversed into.
                    </p>

                    <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                            Current lock
                        </p>
                        <p className="mt-1 font-display text-lg font-semibold text-ink">
                            {lockedThrough
                                ? `Locked through ${lockedThrough}`
                                : 'No period is locked yet'}
                        </p>
                    </div>

                    {canManage ? (
                        <form
                            onSubmit={requestLock}
                            className="flex flex-col gap-4 border-t border-rule pt-6"
                        >
                            <Field
                                label="Lock through date"
                                htmlFor="locked_through"
                                error={errors.locked_through}
                                className="max-w-xs"
                            >
                                <input
                                    id="locked_through"
                                    type="date"
                                    value={form.data.locked_through}
                                    onChange={(event) =>
                                        form.setData(
                                            'locked_through',
                                            event.target.value,
                                        )
                                    }
                                    className={INPUT_CLASSES}
                                />
                            </Field>

                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    disabled={
                                        !form.data.locked_through ||
                                        form.processing
                                    }
                                >
                                    Update lock date
                                </Button>
                                <Button
                                    type="button"
                                    variant="quiet"
                                    onClick={requestUnlock}
                                    disabled={
                                        !form.data.locked_through ||
                                        form.processing
                                    }
                                >
                                    Unlock
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <p className="border-t border-rule pt-6 text-sm text-ink-700">
                            Only owners and accountants can change this.
                        </p>
                    )}
                </div>
            </Sheet>

            {confirmKind && (
                <Dialog open onClose={closeConfirm} className="relative z-50">
                    <DialogBackdrop className="fixed inset-0 bg-ink/30" />
                    <div className="fixed inset-0 flex items-center justify-center p-4">
                        <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                            <DialogTitle className="font-display text-lg font-semibold text-ink">
                                {confirmKind === 'unlock'
                                    ? 'Unlock all periods?'
                                    : 'Lock the books?'}
                            </DialogTitle>
                            <p className="mt-2 text-sm text-ink-700">
                                {confirmKind === 'unlock'
                                    ? 'Every period becomes editable again. This does not undo anything already entered.'
                                    : `Lock the books through ${form.data.locked_through}? Entries on or before this date will be frozen.`}
                            </p>
                            <div className="mt-6 flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="quiet"
                                    onClick={closeConfirm}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    onClick={confirmSubmit}
                                    disabled={form.processing}
                                >
                                    {form.processing
                                        ? 'Saving…'
                                        : confirmKind === 'unlock'
                                          ? 'Unlock'
                                          : 'Lock'}
                                </Button>
                            </div>
                        </DialogPanel>
                    </div>
                </Dialog>
            )}
        </AppShell>
    );
}
