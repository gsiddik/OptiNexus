<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notification\Operational\OperationalMailComposer;
use App\Services\Notification\OperationalMailService;
use Illuminate\Database\Eloquent\Model;

class OperationalMailObserver
{
    public function created(Model $model): void
    {
        if ($model instanceof Price && $model->approval_status === 'APPROVED') {
            $this->price($model);
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Price) {
            if ($model->approval_status === 'APPROVED' && $model->wasChanged(['approval_status', 'effective_from', 'effective_until', 'status'])) {
                $this->price($model);
            }

            return;
        }
        if (! $model->wasChanged('status')) {
            return;
        }
        $mail = app(OperationalMailService::class);
        $compose = app(OperationalMailComposer::class);
        $previous = $model->getOriginal('status');
        if ($model instanceof Invoice && $model->status !== 'DRAFT') {
            $mail->send([$model->tenant_id], 'cgo.invoice.view', $compose->invoiceStatus($model, $previous));
        } elseif ($model instanceof Customer && in_array($model->status, ['SUSPENDED', 'TERMINATED'], true)) {
            $mail->send($model->tenants()->pluck('id')->all(), 'cgo.customer.view', $compose->customerStatus($model, $previous));
        } elseif ($model instanceof Tenant && in_array($model->status, ['SUSPENDED', 'TERMINATED'], true)) {
            $mail->send([$model->id], 'cgo.tenant.view', $compose->tenantStatus($model, $previous));
        } elseif ($model instanceof User && in_array($model->status, ['SUSPENDED', 'DISABLED'], true)) {
            $mail->send($model->tenantMemberships()->pluck('tenant_id')->all(), 'cgo.user.view', $compose->userStatus($model, $previous));
        }
    }

    private function price(Price $price): void
    {
        $tenantIds = Subscription::whereNotIn('status', ['CANCELLED', 'TERMINATED'])
            ->when($price->tenant_id, fn ($q) => $q->where('tenant_id', $price->tenant_id))
            ->where(function ($q) use ($price) {
                if ($price->plan_id) {
                    $q->where('plan_id', $price->plan_id);
                } else {
                    $q->whereHas('items', fn ($i) => $i->inForce()->where('addon_id', $price->addon_id));
                }
            })->pluck('tenant_id')->unique()->values()->all();
        if ($tenantIds === []) {
            return;
        }
        // One tenant per message: a tenant administrator never receives another tenant's commercial context.
        foreach (Tenant::whereIn('id', $tenantIds)->get() as $tenant) {
            app(OperationalMailService::class)->send([$tenant->id], 'cgo.pricing.view', app(OperationalMailComposer::class)->priceChanged($price, $tenant));
        }
    }
}
