<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invoice email itself (spec §17.6): subject "Invoice {number} from
 * {company}", a simple branded body, the invoice PDF attached. Deliberately
 * NOT ShouldQueue — the queued unit of work is SendInvoiceEmail (the job),
 * not this Mailable, so a queue retry re-renders the PDF fresh (PDFs are
 * rendered on demand, never stored — see InvoicePdf's own docblock) instead
 * of re-serializing a possibly-stale binary blob onto the queue payload.
 * $pdfContent therefore always arrives pre-rendered from the job that builds
 * this Mailable, never rendered here.
 */
class InvoiceMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        $this->invoice->loadMissing('company');

        return new Envelope(
            subject: "Invoice {$this->invoice->number} from {$this->invoice->company->name}",
        );
    }

    public function content(): Content
    {
        $this->invoice->loadMissing(['company', 'contact']);

        return new Content(
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'company' => $this->invoice->company,
                'contact' => $this->invoice->contact,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, ($this->invoice->number ?? 'invoice').'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
