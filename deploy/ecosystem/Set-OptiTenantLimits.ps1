#Requires -Version 5.1
<#
.SYNOPSIS
Set per-tenant user_limit for OptiFleet/OptiEntry in a local OptiNexus container.
.DESCRIPTION
Defaults to a transaction preview (rolled back). -Apply commits. No Docker API
pipe is required: this uses podman --connection exec/cp directly.
Validated against the source contracts of OptiNexus main 45ff2c5cdf0f.
Requires a running API container and an ACTIVE PLATFORM_SUPERADMIN operator.
This is a local operator utility, not an HTTP authorization bypass for tenants.

No Limit Set = missing effective user_limit on a seat-controlled application.
Seats Not Controlled = application does not declare user_limit; it does NOT
block additions in SeatService. Setting an override alone does not change that.

-EnableFleetSeatControl explicitly adds user_limit to the Fleet application
manifest metadata in Nexus. This is GLOBAL for Fleet, not tenant-specific.
The transaction refuses activation if another assigned tenant has missing,
invalid, unavailable or over-limit seat capacity. Prepare their overrides first
using -Applications optifleet without -EnableFleetSeatControl.
Reimporting a Fleet manifest with entitlement_limit_keys=[] can undo this local
declaration; align the product manifest in a subsequent repository change.

Limits are whole numbers 0..2000000000 (zero refuses new seats). Overrides have
no expiry. Existing effective app-specific manual overrides are suspended and
audited, not deleted. Future overrides, plans, subscriptions, assignments,
permissions, users and roles are not changed. Tenant-wide manual user_limit
overrides are refused because changing those would affect other products.
Repeated application of the same single override is idempotent.

.EXAMPLE
.\Set-OptiTenantLimits.ps1 -ListTenants
.EXAMPLE
.\Set-OptiTenantLimits.ps1 -Tenant QA-OPTI -OperatorEmail superadmin@cgo.local -FleetLimit 20 -EntryLimit 10 -EnableFleetSeatControl
.EXAMPLE
.\Set-OptiTenantLimits.ps1 -Tenant QA-OPTI -OperatorEmail superadmin@cgo.local -FleetLimit 20 -EntryLimit 10 -EnableFleetSeatControl -Apply
.EXAMPLE
.\Set-OptiTenantLimits.ps1 -Tenant QA-OPTI -OperatorEmail superadmin@cgo.local -Applications optiaccounting -EntryLimit 15 -Apply
#>
[CmdletBinding()]
param(
    [string]$Tenant,
    [string]$OperatorEmail,
    [ValidateSet('optifleet','optiaccounting')]
    [string[]]$Applications = @('optifleet','optiaccounting'),
    [Nullable[int]]$FleetLimit,
    [Nullable[int]]$EntryLimit,
    [ValidateLength(10,1000)]
    [string]$Reason = 'Local QA: configure tenant user seat limits',
    [string]$Connection = 'OptiEcosystem-root',
    [string]$Container = 'optinexus-api-1',
    [switch]$ListTenants,
    [switch]$EnableFleetSeatControl,
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not (Get-Command podman -ErrorAction SilentlyContinue)) {
    throw 'Podman CLI was not found on PATH.'
}
if ($ListTenants -and ($Apply -or $EnableFleetSeatControl)) {
    throw 'ListTenants cannot be combined with Apply or EnableFleetSeatControl.'
}
if (-not $ListTenants) {
    if ([string]::IsNullOrWhiteSpace($Tenant) -or [string]::IsNullOrWhiteSpace($OperatorEmail)) {
        throw 'Specify -Tenant and -OperatorEmail, or use -ListTenants first.'
    }
    if ($EnableFleetSeatControl -and $Applications -notcontains 'optifleet') {
        throw 'EnableFleetSeatControl requires optifleet in Applications.'
    }
}
$limits = [ordered]@{}
if (-not $ListTenants) {
    foreach ($code in @($Applications | Select-Object -Unique)) {
        $limitValue = if ($code -eq 'optifleet') { $FleetLimit } else { $EntryLimit }
        if ($null -eq $limitValue -or $limitValue -lt 0 -or $limitValue -gt 2000000000) {
            throw "Specify the selected application's limit (0..2000000000): $code"
        }
        $limits[$code] = [int]$limitValue
    }
}
$payload = [ordered]@{
    tenant = $Tenant; operator = $OperatorEmail; limits = $limits
    reason = $Reason; apply = [bool]$Apply; list = [bool]$ListTenants
    enable_fleet_seat_control = [bool]$EnableFleetSeatControl
}
$payload64 = [Convert]::ToBase64String(
    [Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json -Depth 6 -Compress))
)

$php = @'
<?php
declare(strict_types=1);

use App\Models\Application;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Commercial\EntitlementService;
use App\Services\Seats\SeatService;
use App\Services\Seats\SeatStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $o = json_decode(base64_decode($argv[1] ?? '', true), true, 512, JSON_THROW_ON_ERROR);
    if (!empty($o['list'])) {
        echo Tenant::query()->orderBy('tenant_code')->get(['id','tenant_code','name','status'])
            ->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    }
    $actor = User::query()->where('email', $o['operator'])->where('status', User::STATUS_ACTIVE)->first();
    if (!$actor || !$actor->userRoles()->whereNull('tenant_id')->whereHas('role',
        fn ($q) => $q->where('code', 'PLATFORM_SUPERADMIN')->where('status', 'ACTIVE')->whereNull('tenant_id'))->exists()) {
        throw new RuntimeException('Operator must be an ACTIVE PLATFORM_SUPERADMIN with a global role assignment.');
    }
    $tenants = Tenant::query()->where(fn ($q) => $q->where('tenant_code', $o['tenant'])->orWhere('id',
        preg_match('/^[0-9a-f-]{36}$/i', $o['tenant']) ? $o['tenant'] : '00000000-0000-0000-0000-000000000000'))->get();
    if ($tenants->count() !== 1) {
        throw new RuntimeException('Tenant not found or ambiguous. Use -ListTenants and specify its exact code or UUID.');
    }
    $tenant = $tenants->first();
    if (!$tenant->isActive()) {
        throw new RuntimeException('Tenant must be ACTIVE; this utility does not provision or activate tenants.');
    }
    $seat = app(SeatService::class);
    $entitlements = app(EntitlementService::class);
    $audit = app(AuditService::class);
    if ($seat->limitKey() !== 'user_limit') {
        throw new RuntimeException('Unsupported seats.limit_key; expected user_limit.');
    }
    $limits = $o['limits'];
    if (!$limits || array_diff(array_keys($limits), ['optifleet','optiaccounting'])) {
        throw new RuntimeException('Only optifleet/optiaccounting are supported.');
    }
    foreach ($limits as $value) {
        if (!is_int($value) || $value < 0 || $value > 2000000000) {
            throw new RuntimeException('Limits must be whole numbers in 0..2000000000.');
        }
    }
    $applications = Application::query()->whereIn('application_code', array_keys($limits))->orderBy('id')->get();
    if ($applications->count() !== count($limits)) {
        throw new RuntimeException('A selected application is missing. Import/register its manifest in Nexus first.');
    }
    foreach ($applications as $product) {
        if (!$product->isPublished() || !$tenant->applications()->where('applications.id', $product->id)
            ->wherePivot('status', 'ACTIVE')->exists()) {
            throw new RuntimeException($product->application_code.': requires a PUBLISHED application and ACTIVE tenant application assignment.');
        }
        if ($product->application_code === 'optiaccounting' && !$seat->declares($product)) {
            throw new RuntimeException('OptiEntry manifest does not declare user_limit. Reimport its current manifest first.');
        }
    }

    DB::beginTransaction();
    DB::statement("SET LOCAL lock_timeout = '5s'");
    // All utilities take this lock before the application's existing seat locks.
    // It serializes global Fleet declaration changes with this utility's overrides.
    DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['local-qa:seat-limit-configuration']);
    $fleet = $applications->firstWhere('application_code', 'optifleet');
    $enable = $fleet && !empty($o['enable_fleet_seat_control']) && !$seat->declares($fleet);
    $pairs = [$tenant->id => $applications->pluck('id')->all()];
    if ($enable) {
        foreach ($fleet->tenants()->wherePivot('status', 'ACTIVE')->get() as $other) {
            $pairs[$other->id] = array_unique(array_merge($pairs[$other->id] ?? [], [$fleet->id]));
        }
    }
    ksort($pairs);
    foreach ($pairs as $tenantId => $applicationIds) {
        $seat->lock($tenantId, $applicationIds);
    }
    $initialSeats = [];
    foreach ($applications as $product) {
        $initialSeats[$product->application_code] = $seat->status($tenant, $product)->toArray();
    }
    $report = ['tenant_id' => $tenant->id, 'tenant_code' => $tenant->tenant_code,
        'operator' => $actor->email, 'reason' => $o['reason'], 'mode' => !empty($o['apply']) ? 'APPLY' : 'PREVIEW_ROLLED_BACK',
        'applications' => [], 'fleet_global_contract_enabled' => false];
    if ($enable) {
        $fleet->refresh();
        $metadata = $fleet->metadata ?? [];
        if (!isset($metadata['manifest']['version'], $metadata['manifest']['revision'])) {
            throw new RuntimeException('Import the Fleet product manifest before enabling its seat contract.');
        }
        $oldMetadata = $metadata;
        $keys = $metadata['manifest']['entitlement_limit_keys'] ?? [];
        if (!is_array($keys)) {
            throw new RuntimeException('Invalid Fleet manifest metadata; repair the manifest first.');
        }
        $metadata['manifest']['entitlement_limit_keys'] = array_values(array_unique([...$keys, 'user_limit']));
        $metadata['local_seat_control'] = ['enabled_at' => now()->toIso8601String(), 'enabled_by' => $actor->id,
            'reason' => $o['reason'], 'source' => 'Set-OptiTenantLimits.ps1'];
        $fleet->update(['metadata' => $metadata]);
        $audit->record('application.seat_control.enabled', actor: $actor, applicationId: $fleet->id,
            resourceType: 'Application', resourceId: $fleet->id, oldValue: ['metadata' => $oldMetadata],
            newValue: ['metadata' => $metadata], source: 'cli', metadata: ['reason' => $o['reason']]);
        $report['fleet_global_contract_enabled'] = true;
    }
    foreach ($applications as $product) {
        $product->refresh();
        $before = $initialSeats[$product->application_code];
        [$used, $reserved] = $seat->usage($tenant->id, $product->id);
        $value = $limits[$product->application_code];
        if ($value < $used + $reserved) {
            throw new RuntimeException($product->application_code.': limit is below used + reserved ('.($used + $reserved).'). No changes committed.');
        }
        $rows = $entitlements->effectiveRows($tenant, $product->application_code)->filter(
            fn ($e) => $e->entitlement_type === Entitlement::TYPE_LIMIT && $e->entitlement_key === 'user_limit'
                && $e->source_type === Entitlement::SOURCE_MANUAL_OVERRIDE);
        if ($rows->contains(fn ($e) => $e->application_id === null)) {
            throw new RuntimeException('An effective tenant-wide manual user_limit exists. Refusing to alter a cross-product override. Review it in Nexus first.');
        }
        $own = $rows->where('application_id', $product->id)->values();
        $changed = !($own->count() === 1 && (string)$own[0]->value === (string)$value);
        if ($changed) {
            foreach ($own as $old) {
                $oldState = $old->only(['status','value','application_id','entitlement_key']);
                $old->update(['status' => Entitlement::STATUS_SUSPENDED]);
                $audit->record('entitlement.suspended', actor: $actor, tenantId: $tenant->id,
                    applicationId: $product->id, resourceType: 'Entitlement', resourceId: $old->id,
                    oldValue: $oldState, newValue: ['status' => Entitlement::STATUS_SUSPENDED], source: 'cli',
                    metadata: ['reason' => $o['reason'], 'utility' => 'Set-OptiTenantLimits.ps1']);
            }
            $new = $entitlements->manualOverride($tenant, ['application_id' => $product->id,
                'entitlement_type' => Entitlement::TYPE_LIMIT, 'entitlement_key' => 'user_limit',
                'value' => (string)$value, 'reason' => $o['reason']], $actor);
            $audit->record('entitlement.overridden', actor: $actor, tenantId: $tenant->id,
                applicationId: $product->id, resourceType: 'Entitlement', resourceId: $new->id,
                newValue: $new->toArray(), source: 'cli', metadata: ['utility' => 'Set-OptiTenantLimits.ps1']);
        }
        $after = $seat->status($tenant, $product);
        if ($after->controlled() && ($after->state !== SeatStatus::LIMITED || $after->limit !== $value)) {
            throw new RuntimeException($product->application_code.': effective limit verification failed ('.$after->state.'). No changes committed.');
        }
        $report['applications'][] = ['application_code' => $product->application_code, 'changed' => $changed,
            'configured_user_limit' => $value, 'before' => $before, 'after' => $after->toArray(),
            'note' => !$after->controlled() ? 'Override stored, but Fleet remains NOT_CONTROLLED. Use -EnableFleetSeatControl to enforce globally.' : null];
    }
    if ($enable) {
        $problems = [];
        $checked = 0;
        foreach ($fleet->tenants()->wherePivot('status', 'ACTIVE')->get() as $other) {
            $state = $seat->status($other, $fleet);
            $checked++;
            if (!in_array($state->state, [SeatStatus::LIMITED, SeatStatus::UNLIMITED], true) || $state->overBy() > 0) {
                $problems[] = $other->tenant_code.': '.$state->state.' (used+reserved='.$state->occupied().')';
            }
        }
        if ($problems) {
            throw new RuntimeException('Global Fleet control would leave assigned tenants without valid capacity: '.implode('; ', $problems).
                '. Prepare their app-specific overrides without -EnableFleetSeatControl first, then retry. Entire transaction rolled back.');
        }
        $report['fleet_assigned_tenants_checked'] = $checked;
    }
    if (!empty($o['apply'])) {
        DB::commit();
    } else {
        DB::rollBack();
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, 'ERROR: '.$e->getMessage().PHP_EOL);
    exit(1);
}
'@

$uniqueId = [Guid]::NewGuid().ToString('N')
$localFile = Join-Path ([IO.Path]::GetTempPath()) "opti-seat-$uniqueId.php"
$remoteFile = "/tmp/opti-seat-$uniqueId.php"
$copied = $false
try {
    $infoText = & podman --connection $Connection inspect $Container
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect Nexus container.' }
    $info = @(($infoText -join "`n") | ConvertFrom-Json)[0]
    if (-not $info.State.Running) { throw 'Nexus API container is not running.' }
    if ($info.Config.Labels.'com.docker.compose.service' -ne 'api') {
        throw 'Expected a Compose API service container.'
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($localFile, $php.Replace("`r`n", "`n"), $utf8NoBom)
    & podman --connection $Connection cp $localFile "${Container}:$remoteFile"
    if ($LASTEXITCODE -ne 0) { throw 'Cannot copy utility into Nexus container.' }
    $copied = $true
    & podman --connection $Connection exec $Container chmod 0644 $remoteFile
    if ($LASTEXITCODE -ne 0) { throw 'Cannot set permissions on the temporary utility.' }
    & podman --connection $Connection exec $Container php -l $remoteFile
    if ($LASTEXITCODE -ne 0) { throw 'PHP syntax check failed. No configuration change was attempted.' }
    & podman --connection $Connection exec --user www-data $Container php $remoteFile $payload64
    if ($LASTEXITCODE -ne 0) { throw 'Seat configuration failed; inspect the ERROR above. The transaction was rolled back.' }
    if (-not $ListTenants) {
        if ($Apply) { Write-Host 'Committed. Refresh the tenant seat panel in Nexus.' }
        else { Write-Host 'Preview only. No database changes persisted. Repeat with -Apply to commit.' }
    }
} finally {
    if ($copied) {
        & podman --connection $Connection exec $Container rm -f $remoteFile | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Warning "Could not remove temporary container file: $remoteFile" }
    }
    if (Test-Path -LiteralPath $localFile) { Remove-Item -LiteralPath $localFile -Force }
}
