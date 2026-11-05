<?php

namespace App;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessPaymentEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $eventId)
    {
        $this->onConnection('database')->onQueue('payments')->afterCommit();
    }

    public function handle(ProcessPaymentEvent $consumer): void
    {
        try {
            $consumer->process($this->eventId);
        } catch (Throwable) {
            throw new RuntimeException('Payment event processing failed; durable inbox recovery pending.');
        }
    }
}
