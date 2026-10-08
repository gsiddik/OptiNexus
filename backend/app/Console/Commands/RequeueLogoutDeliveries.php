<?php

namespace App\Console\Commands;

use App\Services\Oidc\LogoutDeliveryRequeue;
use Illuminate\Console\Command;

class RequeueLogoutDeliveries extends Command
{
    protected $signature = 'oidc:requeue-logout-deliveries
        {--client= : Only deliveries to this OIDC client_id}
        {--user= : Only deliveries for this user id}
        {--max-age=24 : Hours after which a failed LOGOUT call is no longer repeated (it could end a newer session)}
        {--dry-run : Only report what would be sent again}';

    protected $description = 'Send Back-Channel Logout calls again that ended as FAILED, as long as they are still true (safe to run after an application was down)';

    public function handle(LogoutDeliveryRequeue $requeue): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $requeue->requeue(
            $this->option('client') ?: null,
            $this->option('user') ?: null,
            max(1, (int) $this->option('max-age')),
            $dryRun,
        );

        $this->info(($dryRun ? 'Would send again: ' : 'Sent again: ').$result['requeued']);
        $this->line("Left alone because the user signed in again since: {$result['skipped_signed_in_again']}");
        $this->line("Left alone because the logout call is older than the limit: {$result['skipped_too_old']}");
        $this->line("Left alone because access has been restored: {$result['skipped_access_restored']}");
        $this->line("Left alone because the application has no usable logout endpoint: {$result['skipped_no_endpoint']}");

        return self::SUCCESS;
    }
}
