<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
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
        if ($model instanceof Invoice && $model->status !== 'DRAFT') {
            $mail->send([$model->tenant_id], 'cgo.invoice.view', "Invoice {$model->invoice_number}: {$model->status}",
                "Invoice {$model->invoice_number} changed from {$model->getOriginal('status')} to {$model->status}.\nTenant: ".$model->tenant?->name."\nAmount: {$model->currency} {$model->total}\nReview the invoice and follow up in OptiNexus or your tenant application.");
        } elseif ($model instanceof Customer && in_array($model->status, ['SUSPENDED', 'TERMINATED'], true)) {
            $mail->send($model->tenants()->pluck('id')->all(), 'cgo.customer.view', "Customer {$model->customer_code}: {$model->status}",
                "Customer {$model->legal_name} is now {$model->status}. Contact your OptiNexus administrator for follow-up.");
        } elseif ($model instanceof Tenant && in_array($model->status, ['SUSPENDED', 'TERMINATED'], true)) {
            $mail->send([$model->id], 'cgo.tenant.view', "Tenant {$model->name}: {$model->status}", "Tenant {$model->name} is now {$model->status}. Contact your OptiNexus administrator for follow-up.");
        } elseif ($model instanceof User && in_array($model->status, ['SUSPENDED', 'DISABLED'], true)) {
            $mail->send($model->tenantMemberships()->pluck('tenant_id')->all(), 'cgo.user.view', "User {$model->name}: {$model->status}", "User {$model->name} ({$model->email}) is now {$model->status}. Review account access in OptiNexus.");
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
        foreach ($tenantIds as $tenantId) {
            app(OperationalMailService::class)->send([$tenantId], 'cgo.pricing.view', 'Subscription pricing changed',
                'Pricing for '.($price->plan?->name ?? $price->addon?->name).' has changed. Type: '.$price->price_type
                ."\nCurrency: {$price->currency}\nUnit amount: ".($price->unit_amount ?? 'See pricing tiers')
                ."\nEffective from: ".$price->effective_from?->format('d/m/Y, H:i:s')."\nReview the applicable price and follow up with your administrator. Existing issued invoices remain unchanged.");
        }
    }
}
