<?php

namespace App\Jobs;

use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Services\CustomerFulfillment;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDigiflazzCallback implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 20;

    public function __construct(public string $attemptId, public array $data) {}

    public function backoff(): array
    {
        return [2, 5, 10, 20, 30];
    }

    /**
     * Execute the job.
     */
    public function handle(CustomerFulfillment $fulfillment): void
    {
        $attempt = OrderAttempt::whereKey($this->attemptId)->where('source', 'digiflazz')->first();
        $order = $attempt ? CustomerOrder::find($attempt->order_id) : null;
        if ($attempt && $order) {
            $fulfillment->digiflazzCallback($order, $attempt, $this->data);
        }
    }
}
