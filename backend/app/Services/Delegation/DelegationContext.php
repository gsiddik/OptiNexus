<?php

namespace App\Services\Delegation;

use App\Models\Application;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\User;

/**
 * The verified facts behind one delegated call: which product (service account + application), which tenant and which Admin
 * Tenant is acting. Built only by DelegationService::context(); none of it comes from the request payload without validation.
 */
final class DelegationContext
{
    public function __construct(
        public readonly ServiceAccount $serviceAccount,
        public readonly Application $application,
        public readonly Tenant $tenant,
        public readonly User $actor,
    ) {}

    /** @return array<string, mixed> */
    public function auditMetadata(): array
    {
        return ['delegated' => true, 'service_account_id' => $this->serviceAccount->id, 'acting_user_id' => $this->actor->id];
    }
}
