<?php

namespace App\Services\Ecosystem;

use App\Jobs\SendAccountCredential;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ManagedCredentialService
{
    /** Caller holds the user row lock. Existing email verification is preserved on reactivation only. */
    public function restart(User $user, bool $verifyEmail = false): string
    {
        if ($verifyEmail && ! app()->environment('testing') && ! in_array(config('mail.default'), ['smtp', 'postmark', 'ses', 'ses-v2', 'resend', 'mailgun'], true)) {
            throw ValidationException::withMessages(['email' => 'Configure a transactional email provider before changing a tenant account email.']);
        }
        $user->forceFill([
            'status' => User::STATUS_INVITED,
            'activation_started_at' => now(),
            'activated_at' => null,
        ])->save();
        $plain = app(AccountActivationService::class)->issueTemporaryPassword($user);
        if ($verifyEmail) {
            $user->forceFill(['verify_email_on_password_change' => true])->save();
        }
        SendAccountCredential::dispatch($user->id, $user->email, $plain)->afterCommit();

        return $plain;
    }
}
