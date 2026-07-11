<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * §17.9 dead-man's-switch heartbeat pings (healthchecks.io-style). One
 * `ping()` call per scheduled lifeline, made right after that lifeline
 * completes successfully — see VerifyLedgerIntegrity (lifeline 'integrity'),
 * BackupDatabase (lifeline 'backup'), QueueHeartbeatJob (lifeline 'queue').
 *
 * Config-gated on config('monitoring.heartbeats.*') exactly like Sentry's
 * DSN (config/sentry.php): an unset URL is a clean no-op, never an error —
 * every lifeline ships with monitoring inert by default. A network failure
 * while pinging is swallowed (logged as a warning, never thrown) — a
 * heartbeat's own flakiness must never fail the command it's reporting
 * success FOR; the whole point of a dead-man's-switch is that SILENCE (not
 * a thrown exception here) is what the external service pages on.
 */
class Heartbeat
{
    public function ping(string $lifeline): void
    {
        $url = config("monitoring.heartbeats.{$lifeline}");

        if (blank($url)) {
            return;
        }

        try {
            Http::timeout(5)->get($url);
        } catch (Throwable $e) {
            Log::warning("Heartbeat ping failed for lifeline [{$lifeline}].", [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
