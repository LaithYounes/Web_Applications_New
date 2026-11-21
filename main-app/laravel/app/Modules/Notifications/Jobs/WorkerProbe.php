<?php

namespace App\Modules\Notifications\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WorkerProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $taskNumber)
    {
    }

    public function handle(): void
    {
        sleep(2);

        logger()->info('WORKER_PROBE', [
            'task' => $this->taskNumber,
            'host' => gethostname(),
        ]);
    }
}