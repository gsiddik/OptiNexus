<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Tenant;
use App\Services\Seats\SeatService;
use Illuminate\Console\Command;

/**
 * Read-only seat inventory (owner decision 2026-10-18; GOVERNANCE_CONTRACTS.md 2d): for every tenant and application, what is used,
 * reserved and allowed, and which rows exist that hold no seat. It is the preview to run before enabling seat control on an install
 * and the reconciliation afterwards. It never changes anything: no access is granted or revoked, no invitation or entitlement edited.
 */
class SeatInventory extends Command
{
    protected $signature = 'ecosystem:seats:inventory
        {--tenant= : tenant code or id}
        {--application= : application code}
        {--only-problems : list only seat-controlled pairs that are over limit, undecided or have anomalies}
        {--json : print JSON instead of a table}
        {--strict : exit with failure when any seat-controlled pair is over limit or undecided (a deployment gate)}';

    protected $description = 'Report seats per tenant and application (used, reserved, limit, over-limit, anomalies). Read-only.';

    public function handle(SeatService $seats): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $value) => $q->where(fn ($q2) => $q2->where('tenant_code', $value)->orWhere('id', preg_match('/^[0-9a-f-]{36}$/i', (string) $value) ? $value : null)))
            ->orderBy('tenant_code')->get();

        $rows = [];
        $failing = false;

        foreach ($tenants as $tenant) {
            $applications = $tenant->applications()->wherePivot('status', 'ACTIVE')
                ->when($this->option('application'), fn ($q, $value) => $q->where('applications.application_code', $value))
                ->orderBy('applications.application_code')->get();

            /** @var Application $application */
            foreach ($applications as $application) {
                $status = $seats->status($tenant, $application);
                $anomalies = $seats->anomalies($tenant->id, $application->id);
                $problem = $status->controlled() && ($status->overBy() > 0 || ! $status->decided());
                $failing = $failing || $problem;

                if ($this->option('only-problems') && ! $problem && array_sum($anomalies) === 0) {
                    continue;
                }

                $rows[] = ['tenant' => $tenant->tenant_code, 'tenant_id' => $tenant->id] + $status->toArray() + ['anomalies' => $anomalies];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Tenant', 'Application', 'State', 'Limit', 'Used', 'Reserved', 'Available', 'Over by', 'Anomalies'],
                array_map(fn (array $r): array => [
                    $r['tenant'], $r['application_code'], $r['state'], $r['unlimited'] ? 'unlimited' : ($r['limit'] ?? '-'),
                    $r['used'], $r['reserved'], $r['available'] ?? '-', $r['over_by'],
                    collect($r['anomalies'])->filter()->map(fn ($n, $k) => "{$k}={$n}")->implode(', ') ?: '-',
                ], $rows),
            );
        }

        return $this->option('strict') && $failing ? self::FAILURE : self::SUCCESS;
    }
}
