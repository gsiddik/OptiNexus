<?php

namespace App\Console\Commands;

use App\Models\OidcSession;
use App\Services\Oidc\SessionRevocationService;
use Illuminate\Console\Command;

class ReconcileOidcAccess extends Command
{
    protected $signature = 'oidc:reconcile-access';

    protected $description = 'End application sessions whose access no longer holds and tell the applications (safety net behind the immediate hooks)';

    public function handle(SessionRevocationService $revocation): int
    {
        $ended = $revocation->reconcile();

        // Housekeeping: forget sessions that have been over for a while.
        OidcSession::query()->where(function ($q) {
            $q->whereNotNull('ended_at')->where('ended_at', '<', now()->subDays(30));
        })->orWhere('expires_at', '<', now()->subDays(30))->delete();

        $this->info("Ended {$ended} session(s) whose access was revoked.");

        return self::SUCCESS;
    }
}
