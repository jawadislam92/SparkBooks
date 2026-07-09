import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import PaymentModal, { PaymentModalBankAccount } from '@/Components/invoices/PaymentModal';
import StatusChip from '@/Components/invoices/StatusChip';
import VoidDialog from '@/Components/invoices/VoidDialog';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useState } from 'react';

interface InvoiceLineData {
    id: number;
    description: string;
    quantity: string;
    /** Integer minor units, document currency. */
    unit_price: number;
    line_subtotal: number;
    line_tax: number;
}

interface InvoicePaymentData {
    id: number;
    payment_date: string;
    /** Integer minor units, document currency. */
    amount: number;
    /** Integer minor units, base currency — the actual amount the bank credited. */
    base_amount: number;
    base_ar_relief: number;
    /** Payment-date implied rate (display only), null for a domestic invoice. */
    fx_rate: string | null;
    status: 'posted' | 'void' | string;
    memo: string | null;
    bank_account: { id: number; name: string } | null;
}

interface InvoiceShowData {
    id: number;
    number: string | null;
    status: string;
    is_opening: boolean;
    contact: { id: number; name: string };
    currency_code: string;
    fx_rate: string;
    issue_date: string | null;
    due_date: string | null;
    notes: string | null;
    subtotal: number;
    tax_total: number;
    total: number;
    amount_paid: number;
    balance: number;
    base_subtotal: number;
    base_tax_total: number;
    base_total: number;
    email_status: 'not_sent' | 'queued' | 'sent' | 'failed' | string;
    sent_at: string | null;
    voided_at: string | null;
    lines: InvoiceLineData[];
    payments: InvoicePaymentData[];
}

interface ShowPageProps extends PageProps {
    invoice: InvoiceShowData;
    bankAccounts: PaymentModalBankAccount[];
}

const EMAIL_STATUS_LABELS: Record<string, string> = {
    not_sent: 'Not sent',
    queued: 'Queued',
    sent: 'Sent',
    failed: 'Failed',
};

const EMAIL_STATUS_CLASSES: Record<string, string> = {
    not_sent: 'bg-paper text-ink-700',
    queued: 'bg-lagoon/10 text-lagoon',
    sent: 'bg-spark-50 text-ink',
    failed: 'bg-clay-50 text-clay',
};

function EmailStatusChip({ status }: { status: string }) {
    return (
        <span
            className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${
                EMAIL_STATUS_CLASSES[status] ?? 'bg-paper text-ink-700'
            }`}
        >
            Email: {EMAIL_STATUS_LABELS[status] ?? status}
        </span>
    );
}

function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark disabled:cursor-not-allowed disabled:opacity-60';

interface SendFormData {
    fx_rate: string;
}

/**
 * Confirms the irreversible "send" action (assigns a document number, posts
 * posting rule P1/P1fx to the ledger) — for a foreign-currency invoice this
 * is also where the send-time FX rate (spec §17.1: captured at send, not
 * before) is entered.
 */
function SendInvoiceDialog({
    invoice,
    baseCurrency,
    onClose,
}: {
    invoice: { id: number; number: string | null; currency_code: string };
    baseCurrency: string;
    onClose: () => void;
}) {
    const isForeign = invoice.currency_code !== baseCurrency;

    // Stable for this dialog's whole open session — a validation-error retry
    // (e.g. a malformed fx_rate) reuses it; reopening the dialog is a fresh
    // mount and gets a fresh one.
    const [sendKey] = useState(() => crypto.randomUUID());
    const form = useForm<SendFormData>({ fx_rate: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(route('invoices.send', invoice.id), {
            headers: { 'X-Idempotency-Key': sendKey },
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-sm rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        Send invoice
                    </DialogTitle>
                    <p className="mt-1 text-sm text-ink-700">
                        Assigns a document number and posts it to the ledger. Correcting a sent invoice
                        afterward means voiding and reissuing it, not editing it.
                    </p>

                    {errors.status && (
                        <p role="alert" className="mt-3 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                            {errors.status}
                        </p>
                    )}

                    <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                        {isForeign && (
                            <Field
                                label={`Exchange rate (1 ${invoice.currency_code} = ? ${baseCurrency})`}
                                htmlFor="send-fx-rate"
                                error={errors.fx_rate}
                            >
                                <input
                                    id="send-fx-rate"
                                    type="text"
                                    inputMode="decimal"
                                    placeholder="e.g. 280"
                                    value={form.data.fx_rate}
                                    onChange={(event) => form.setData('fx_rate', event.target.value)}
                                    className={INPUT_CLASSES}
                                    autoFocus
                                />
                            </Field>
                        )}
                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose} disabled={form.processing}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Sending…' : 'Send invoice'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

export default function Show({ invoice, bankAccounts }: ShowPageProps) {
    const { currentCompany, can, errors: pageErrors } = usePage<PageProps>().props;
    const baseCurrency = currentCompany?.base_currency ?? 'PKR';
    const isForeign = invoice.currency_code !== baseCurrency;
    const isDraft = invoice.status === 'draft';
    const isPosted = ['sent', 'partially_paid', 'paid'].includes(invoice.status);
    const canReceivePayment = invoice.status === 'sent' || invoice.status === 'partially_paid';
    const canVoid = ['sent', 'partially_paid', 'paid'].includes(invoice.status);
    const overdue =
        (invoice.status === 'sent' || invoice.status === 'partially_paid') &&
        invoice.due_date !== null &&
        invoice.due_date < todayIsoDate();

    const [sendDialogOpen, setSendDialogOpen] = useState(false);
    const [paymentModalOpen, setPaymentModalOpen] = useState(false);
    const [voidDialogOpen, setVoidDialogOpen] = useState(false);
    const [emailAction, setEmailAction] = useState<'send' | 'resend' | null>(null);

    const errors = (pageErrors ?? {}) as Record<string, string | undefined>;

    function submitEmail(action: 'send' | 'resend') {
        setEmailAction(action);
        const routeName = action === 'send' ? 'invoices.email' : 'invoices.email.resend';
        router.post(
            route(routeName, invoice.id),
            {},
            {
                headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                preserveScroll: true,
                onFinish: () => setEmailAction(null),
            },
        );
    }

    return (
        <AppShell title={invoice.number ?? 'Draft invoice'}>
            <Head title={invoice.number ?? 'Draft invoice'} />

            <Link
                href={route('invoices.index')}
                className="mb-4 inline-block text-sm font-medium text-lagoon hover:underline"
            >
                &larr; Back to invoices
            </Link>

            {errors.status && (
                <div role="alert" className="mb-4 rounded-md bg-clay-50 px-4 py-3 text-sm text-clay">
                    {errors.status}
                </div>
            )}

            <Sheet className="mb-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-mono text-2xl font-semibold text-ink">
                                {invoice.number ?? 'Draft'}
                            </h2>
                            <StatusChip status={invoice.status} />
                            {overdue && (
                                <span className="inline-block rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-ink">
                                    Overdue
                                </span>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-ink-700">{invoice.contact.name}</p>
                        <div className="mt-3 flex gap-6 text-sm">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    Issue date
                                </p>
                                <p className="text-ink">{formatDate(invoice.issue_date)}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    Due date
                                </p>
                                <p className="text-ink">{formatDate(invoice.due_date)}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    Currency
                                </p>
                                <p className="text-ink">{invoice.currency_code}</p>
                            </div>
                        </div>
                    </div>

                    <div className="text-right">
                        <p className="text-xs font-medium uppercase tracking-wide text-ink-700">Total</p>
                        <MoneyText minor={invoice.total} currency={invoice.currency_code} size="lg" />
                        <p className="mt-2 text-xs font-medium uppercase tracking-wide text-ink-700">
                            Balance
                        </p>
                        <MoneyText minor={invoice.balance} currency={invoice.currency_code} size="md" />
                        {isForeign && isPosted && (
                            <div className="mt-2">
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    ≈ in {baseCurrency}
                                </p>
                                <MoneyText minor={invoice.base_total} currency={baseCurrency} size="sm" />
                                <p className="mt-0.5 text-xs text-ink-700">
                                    at 1 {invoice.currency_code} ={' '}
                                    {Number(invoice.fx_rate).toLocaleString('en-PK', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 4,
                                    })}{' '}
                                    {baseCurrency}
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                {invoice.is_opening && (
                    <p className="mt-4 rounded-md bg-paper px-3 py-2 text-sm text-ink-700">
                        Opening invoice — manage via opening balances, not here. It can&apos;t be voided
                        from this screen.
                    </p>
                )}

                <div className="mt-6 flex flex-wrap items-center gap-2 border-t border-rule pt-4">
                    {isDraft && (
                        <Button
                            type="button"
                            variant="quiet"
                            onClick={() => router.visit(route('invoices.edit', invoice.id))}
                        >
                            Edit draft
                        </Button>
                    )}
                    {isDraft && can['invoices.send'] && (
                        <Button type="button" onClick={() => setSendDialogOpen(true)}>
                            Send invoice
                        </Button>
                    )}
                    {canReceivePayment && can['payments.record'] && (
                        <Button type="button" onClick={() => setPaymentModalOpen(true)}>
                            Record payment
                        </Button>
                    )}
                    {canVoid && !invoice.is_opening && can['invoices.void'] && (
                        <Button type="button" variant="danger" onClick={() => setVoidDialogOpen(true)}>
                            Void
                        </Button>
                    )}
                    <a
                        href={route('invoices.pdf', invoice.id)}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-transparent px-4 text-sm font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised"
                    >
                        Download PDF
                    </a>
                    {isPosted && can['invoices.send'] && (
                        <>
                            {invoice.email_status === 'not_sent' && (
                                <Button
                                    type="button"
                                    variant="quiet"
                                    disabled={emailAction !== null}
                                    onClick={() => submitEmail('send')}
                                >
                                    {emailAction === 'send' ? 'Sending…' : 'Send email'}
                                </Button>
                            )}
                            {(invoice.email_status === 'failed' || invoice.email_status === 'sent') && (
                                <Button
                                    type="button"
                                    variant="quiet"
                                    disabled={emailAction !== null}
                                    onClick={() => submitEmail('resend')}
                                >
                                    {emailAction === 'resend' ? 'Resending…' : 'Resend email'}
                                </Button>
                            )}
                            <EmailStatusChip status={invoice.email_status} />
                        </>
                    )}
                </div>
            </Sheet>

            <Sheet title="Line items" className="mb-6">
                <table className="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                            <th scope="col" className="py-2 pr-3 font-medium">Description</th>
                            <th scope="col" className="py-2 pr-3 text-right font-medium">Qty</th>
                            <th scope="col" className="py-2 pr-3 text-right font-medium">Unit price</th>
                            <th scope="col" className="py-2 pl-3 text-right font-medium">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoice.lines.map((line) => (
                            <tr key={line.id} className="border-b border-rule last:border-b-0">
                                <td className="py-3 pr-3">{line.description}</td>
                                <td className="py-3 pr-3 text-right font-mono tabular-nums">
                                    {line.quantity}
                                </td>
                                <td className="py-3 pr-3">
                                    <MoneyText minor={line.unit_price} currency={invoice.currency_code} size="sm" />
                                </td>
                                <td className="py-3 pl-3">
                                    <MoneyText
                                        minor={line.line_subtotal + line.line_tax}
                                        currency={invoice.currency_code}
                                        size="sm"
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <div className="mt-4 flex flex-col items-end gap-1 border-t border-rule pt-4">
                    <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                        <span>Subtotal</span>
                        <MoneyText minor={invoice.subtotal} currency={invoice.currency_code} size="sm" />
                    </div>
                    <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                        <span>Tax</span>
                        <MoneyText minor={invoice.tax_total} currency={invoice.currency_code} size="sm" />
                    </div>
                    <div className="flex w-full max-w-xs items-center justify-between text-base font-semibold text-ink">
                        <span>Total</span>
                        <MoneyText minor={invoice.total} currency={invoice.currency_code} size="md" />
                    </div>
                </div>

                {invoice.notes && (
                    <p className="mt-4 whitespace-pre-line border-t border-rule pt-4 text-sm text-ink-700">
                        {invoice.notes}
                    </p>
                )}
            </Sheet>

            <Sheet title="Payments">
                {invoice.payments.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">No payments recorded yet.</p>
                ) : (
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">Date</th>
                                <th scope="col" className="py-2 pr-3 font-medium">Account</th>
                                <th scope="col" className="py-2 pr-3 font-medium">Status</th>
                                <th scope="col" className="py-2 pr-3 text-right font-medium">Amount</th>
                                {isForeign && (
                                    <>
                                        <th scope="col" className="py-2 pr-3 text-right font-medium">
                                            {baseCurrency} received
                                        </th>
                                        <th scope="col" className="py-2 pl-3 text-right font-medium">
                                            Implied rate
                                        </th>
                                    </>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.payments.map((payment) => (
                                <tr key={payment.id} className="border-b border-rule last:border-b-0">
                                    <td className="py-3 pr-3 align-top text-ink-700">
                                        {formatDate(payment.payment_date)}
                                    </td>
                                    <td className="py-3 pr-3 align-top">
                                        {payment.bank_account?.name ?? '—'}
                                    </td>
                                    <td className="py-3 pr-3 align-top">
                                        {payment.status === 'void' ? (
                                            <span className="inline-block rounded-full bg-clay-50 px-2 py-0.5 text-xs font-medium text-clay">
                                                Reversed
                                            </span>
                                        ) : (
                                            <span className="inline-block rounded-full bg-spark-50 px-2 py-0.5 text-xs font-medium text-ink">
                                                Posted
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-3 pr-3 align-top">
                                        <MoneyText
                                            minor={payment.amount}
                                            currency={invoice.currency_code}
                                            size="sm"
                                        />
                                    </td>
                                    {isForeign && (
                                        <>
                                            <td className="py-3 pr-3 align-top">
                                                <MoneyText
                                                    minor={payment.base_amount}
                                                    currency={baseCurrency}
                                                    size="sm"
                                                />
                                            </td>
                                            <td className="py-3 pl-3 align-top text-right font-mono tabular-nums text-ink-700">
                                                {payment.fx_rate
                                                    ? Number(payment.fx_rate).toLocaleString('en-PK', {
                                                          minimumFractionDigits: 2,
                                                          maximumFractionDigits: 4,
                                                      })
                                                    : '—'}
                                            </td>
                                        </>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Sheet>

            {sendDialogOpen && (
                <SendInvoiceDialog
                    invoice={invoice}
                    baseCurrency={baseCurrency}
                    onClose={() => setSendDialogOpen(false)}
                />
            )}
            {paymentModalOpen && (
                <PaymentModal
                    invoice={invoice}
                    bankAccounts={bankAccounts}
                    baseCurrency={baseCurrency}
                    onClose={() => setPaymentModalOpen(false)}
                />
            )}
            {voidDialogOpen && (
                <VoidDialog invoice={invoice} onClose={() => setVoidDialogOpen(false)} />
            )}
        </AppShell>
    );
}
