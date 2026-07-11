<?php

/**
 * §17.9 monitoring & heartbeats. Config-gated, same "wired but inert without
 * env" pattern as Sentry (config/sentry.php) and the backups off-host disk
 * (config/filesystems.php's "backups" disk): every value here defaults to
 * null, and App\Support\Heartbeat / App\Support\AlertNotifier both no-op
 * cleanly whenever the relevant value is unset — nothing here can ever throw
 * just because monitoring isn't configured.
 *
 * Heartbeats: dead-man's-switch pings (healthchecks.io-style) for the three
 * scheduled lifelines — daily backup, the integrity checker, and
 * queue-worker liveness (via QueueHeartbeatJob). A configured URL gets
 * pinged once its lifeline completes successfully; alerting on SILENCE (a
 * lifeline that stops pinging) is the external service's job, not ours — we
 * only ever ping on success.
 *
 * Alerts: email + optional Slack, fired the moment a backup fails or
 * ledger:verify finds anything (or evaluates zero companies) — see
 * App\Console\Commands\BackupDatabase / VerifyLedgerIntegrity, wired through
 * App\Support\AlertNotifier.
 */
return [

    'heartbeats' => [
        'backup' => env('BACKUP_HEARTBEAT_URL'),
        'integrity' => env('INTEGRITY_HEARTBEAT_URL'),
        'queue' => env('QUEUE_HEARTBEAT_URL'),
    ],

    'alert_email' => env('MONITORING_ALERT_EMAIL'),

    'slack_webhook' => env('MONITORING_SLACK_WEBHOOK'),

];
