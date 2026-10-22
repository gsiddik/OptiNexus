<?php

namespace App\Services\Ecosystem;

use App\Jobs\SendAccountCredential;
use App\Models\User;

class ManagedCredentialService
{
    /** Caller holds the user row lock. Existing email verification is preserved on reactivation only. */
    public function restart(User $user): string
    {
        $user->forceFill([
            'status' => User::STATUS_INVITED,
            'activation_started_at' => now(),
            'activated_at' => null,
        ])->save();
        $plain = app(AccountActivationService::class)->issueTemporaryPassword($user);
        SendAccountCredential::dispatch($user->id, $user->email, $plain)->afterCommit();

        return $plain;
    }
}
