<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §17.9 monitoring alert email — sent by App\Support\AlertNotifier the
 * instant a backup fails or ledger:verify finds anything (or evaluates zero
 * companies). Deliberately NOT ShouldQueue: an alert about a broken system
 * shouldn't silently wait behind a possibly-also-broken queue worker (see
 * QueueHeartbeatJob's own docblock for why the queue itself can go dark
 * undetected) — AlertNotifier sends this synchronously, inline with the
 * failure it's reporting.
 *
 * Properties are named alertSubject/alertBody rather than subject/body to
 * avoid colliding with Mailable's own public $subject property.
 */
class MonitoringAlertMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $alertSubject,
        public readonly string $alertBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.monitoring-alert',
            with: ['body' => $this->alertBody],
        );
    }
}
