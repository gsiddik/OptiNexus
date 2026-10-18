<?php

namespace App\Console\Commands;

use App\Services\Delegation\InvitationService;
use Illuminate\Console\Command;

class ExpireInvitations extends Command
{
    protected $signature = 'invitations:expire';

    protected $description = 'Mark user invitations whose time ran out as EXPIRED (they are reported as expired before this runs; this keeps the table and the audit trail honest)';

    public function handle(InvitationService $invitations): int
    {
        $this->info('Expired '.$invitations->expireStale().' invitation(s).');

        return self::SUCCESS;
    }
}
