<?php

namespace App;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessNotificationDeliveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId)
    {
        $this->onConnection('database')->onQueue('notifications')->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationOutbox $outbox, NotificationTransport $transport): void
    {
        try {
            $outbox->deliver($this->deliveryId, $transport);
        } catch (Throwable) {
            throw new RuntimeException('Notification delivery failed; inspect the durable outbox.');
        }
    }
}
