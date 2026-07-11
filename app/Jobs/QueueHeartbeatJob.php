<?php

namespace App\Jobs;

use App\Support\Heartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * §17.9 queue-worker liveness dead-man's-switch. Dispatched nightly by
 * `sparkbooks:queue-heartbeat` (see routes/console.php). The heartbeat ping
 * only fires from INSIDE handle() — i.e. only once a real `queue:work`
 * process actually pulls this job off the queue and processes it.
 *
 * This is the entire point of the job-on-queue design over a plain
 * scheduled command: a scheduled command's own execution "proves" nothing
 * about the queue being alive (cron fires it regardless of whether any
 * worker exists). A dead/stalled worker means this job just sits queued
 * forever, handle() never runs, the configured heartbeat URL never gets
 * pinged, and the external healthcheck service pages on that silence — the
 * same dead-man's-switch shape as the backup/integrity heartbeats, but
 * proving worker liveness specifically rather than command-ran-at-all.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->ping('queue');
    }
}
