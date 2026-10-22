<?php

namespace App\Services\Notification;

use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\AuthorizationService;

/** Transactional notification outbox, scoped to registered admins and authorized Nexus operators. */
class OperationalMailService
{
    public function send(array $tenantIds, string $permission, string $subject, string $body): void
    {
        $recipients = User::where('status', User::STATUS_ACTIVE)->where(function ($q) use ($tenantIds) {
            $q->whereHas('tenantMemberships', fn ($m) => $m->whereIn('tenant_id', $tenantIds)->where('status', 'ACTIVE')->where('is_tenant_admin', true))
                ->orWhereHas('userRoles', fn ($r) => $r->whereNull('tenant_id')->whereHas('role', fn ($role) => $role->where('role_type', 'SYSTEM')->where('status', 'ACTIVE')));
        })->get();
        foreach ($recipients as $user) {
            $tenantAdmin = $user->tenantMemberships()->whereIn('tenant_id', $tenantIds)->where('status', 'ACTIVE')->where('is_tenant_admin', true)->exists();
            $auth = app(AuthorizationService::class);
            if (! $tenantAdmin && (! $auth->hasEcosystemAccess($user) || ! $auth->userHasPermission($user, $permission))) {
                continue;
            }
            $notification = Notification::create([
                'tenant_id' => count($tenantIds) === 1 ? $tenantIds[0] : null,
                'recipient_user_id' => $user->id, 'channel' => 'EMAIL',
                'subject' => $subject, 'body' => $body, 'status' => Notification::STATUS_QUEUED,
            ]);
            SendNotification::dispatch($notification->id)->afterCommit();
        }
    }
}
