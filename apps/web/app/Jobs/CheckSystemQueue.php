<?php

namespace App\Jobs;

use App\Services\SystemHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckSystemQueue implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public string $token) {}

    public function handle(SystemHealth $health): void
    {
        $health->completeProbe($this->token);
    }

    public function failed(?\Throwable $exception): void
    {
        try {
            app(SystemHealth::class)->failProbe($this->token);
        } catch (\Throwable) {
            // The failed-job record remains available if monitoring storage is down.
        }
    }
}
