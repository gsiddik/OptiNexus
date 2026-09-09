<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\Notification\NotificationDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public string $notificationId) {}

    public function handle(NotificationDispatchService $dispatch): void
    {
        $notification = Notification::find($this->notificationId);
        if (! $notification || ! $notification->isOpen()) {
            return;
        }

        try {
            $dispatch->attemptDelivery($notification);
        } catch (\Throwable $e) {
            $notification->refresh();

            if ($notification->status === Notification::STATUS_CANCELLED) {
                return; // recipient opted out - terminal, no further retry
            }

            if ($this->attempts() >= $this->tries) {
                $dispatch->markFailed($notification, $e->getMessage());

                return;
            }

            $notification->update(['next_retry_at' => now()->addSeconds($this->backoff[$this->attempts() - 1] ?? 300)]);

            throw $e; // let the queue's own backoff/tries retry a transient failure
        }
    }
}
