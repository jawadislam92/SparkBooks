<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §9 correctness watchdog: verify every company's ledger daily.
Schedule::command('ledger:verify')->dailyAt('03:00');

// §11 backup: dump the database + attachments, copy off-host when
// configured, and prune to 14 daily + 8 weekly. Runs before ledger:verify
// so a bad night's data is caught only after that night is already backed
// up; withoutOverlapping guards against a slow run still going when the
// next day's schedule fires.
Schedule::command('sparkbooks:backup')->dailyAt('02:00')->withoutOverlapping();

// §17.9 monitoring — queue-worker liveness dead-man's-switch. Dispatches
// QueueHeartbeatJob onto the queue; the actual heartbeat ping only fires
// once a real queue:work process pulls the job off and processes it (see
// QueueHeartbeatJob's docblock), so a dead worker silently fails to ping
// and the configured external healthcheck service pages on that silence.
// The backup/integrity heartbeat pings themselves are NOT scheduled here —
// they live inside sparkbooks:backup / ledger:verify and fire only after
// each command's own success.
Schedule::command('sparkbooks:queue-heartbeat')->dailyAt('04:00');
