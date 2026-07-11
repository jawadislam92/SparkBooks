<?php

namespace App\Support;

use App\Mail\MonitoringAlertMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * §17.9 alert-the-moment-it-happens channel — email + optional Slack, both
 * config-gated exactly like Sentry's DSN (config/sentry.php): unset means
 * inert. Called by VerifyLedgerIntegrity (findings, or zero companies
 * evaluated) and BackupDatabase (any failure) the instant those conditions
 * are detected — NOT dependent on the heartbeat/silence mechanism
 * (App\Support\Heartbeat handles "detect eventually via absence"; this class
 * is "tell someone right now").
 *
 * Email-only works with no Slack webhook configured, and vice versa. If
 * NEITHER is configured, nothing is sent but a warning is still logged —
 * so a genuine failure stays visible in application logs even with
 * monitoring left completely unconfigured. Each channel's own send failure
 * (bad SMTP creds, an unreachable Slack webhook) is caught and logged
 * separately — a broken alert channel must never throw back into the
 * command that's trying to report a REAL failure.
 */
class AlertNotifier
{
    public function alert(string $subject, string $body): void
    {
        $email = config('monitoring.alert_email');
        $slackWebhook = config('monitoring.slack_webhook');

        if (blank($email) && blank($slackWebhook)) {
            Log::warning('Monitoring alert would have fired but no alert channel (email/Slack) is configured.', [
                'subject' => $subject,
                'body' => $body,
            ]);

            return;
        }

        if (filled($email)) {
            try {
                Mail::to($email)->send(new MonitoringAlertMail($subject, $body));
            } catch (Throwable $e) {
                Log::error('Monitoring alert email failed to send.', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (filled($slackWebhook)) {
            try {
                Http::timeout(5)->post($slackWebhook, [
                    'text' => "*{$subject}*\n{$body}",
                ]);
            } catch (Throwable $e) {
                Log::error('Monitoring alert Slack webhook POST failed.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
