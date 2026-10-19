<?php

namespace App\Jobs;

use App\Models\CustomerOrder;
use App\Services\CustomerFulfillment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCustomerOrder implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $orderId) {}

    /**
     * Execute the job.
     */
    public function handle(CustomerFulfillment $fulfillment): void
    {
        $order = CustomerOrder::find($this->orderId);
        if ($order) {
            $fulfillment->run($order);
        }
    }
}
