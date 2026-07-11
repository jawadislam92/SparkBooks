<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Console\Command;

/**
 * §17.9 queue-worker liveness. This command's own execution proves nothing
 * by itself — the scheduler fires it on cron regardless of whether a queue
 * worker is even running. All handle() does is DISPATCH QueueHeartbeatJob
 * onto the queue; the actual heartbeat ping lives in that job's handle(),
 * which only runs once a genuine `queue:work` process pulls it off the
 * queue. See QueueHeartbeatJob's own docblock for the full dead-man's-switch
 * reasoning (a dead worker never processes the job, so the heartbeat URL
 * never gets pinged, and the external healthcheck service pages on that
 * silence).
 */
class QueueHeartbeat extends Command
{
    protected $signature = 'sparkbooks:queue-heartbeat';

    protected $description = 'Dispatch a job onto the queue whose processing proves a worker is alive (spec §17.9)';

    public function handle(): int
    {
        QueueHeartbeatJob::dispatch();

        $this->info('Queue heartbeat job dispatched.');

        return self::SUCCESS;
    }
}
