<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Ecosystem\AccountActivationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/** The temporary secret is encrypted in the queue and never persisted in notification/audit bodies. */
class SendAccountCredential implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public string $userId, public string $email, private string $password) {}

    public function handle(AccountActivationService $activation): void
    {
        $user = User::find($this->userId);
        if (! $user || $user->email !== $this->email || ! $user->hasUsableTemporaryPassword()
            || ! $activation->matchesTemporaryPassword($user, $this->password)) {
            return;
        }
        if (! app()->environment('testing') && in_array(config('mail.default'), ['log', 'array'], true)) {
            throw new \RuntimeException('Configure a transactional mail provider before delivering account credentials.');
        }
        $body = "Your OptiNexus account has a new temporary password.\n\nEmail: {$this->email}\nTemporary password: {$this->password}\n\nSign in through OptiNexus from OptiFleet or OptiEntry and choose a new password before accessing the application. This password expires at ".$user->temporary_password_expires_at->format('d/m/Y, H:i:s T').".\nIf your email verification is pending, contact your OptiNexus administrator.\nNever share this password.";
        Mail::raw($body, fn ($message) => $message->to($this->email)->subject('OptiNexus — temporary account password'));
    }
}
