<#
.SYNOPSIS
  Loads the demo tenant of the whole ecosystem: OptiNexus first (customer, tenant, suite subscription, administrator), then the demo
  data of every application that has any (OptiFleet, OptiEntry, OptiDeli, OptiStash), in order, so that what one platform creates
  reaches the others through the real OptiNexus events (vehicles to OptiFleet and OptiRadar, the pickup request to OptiDeli, ...).

.DESCRIPTION
  Local installations only. Safe to run again: what exists is kept. The administrator's temporary password is written to
  .state\demo-ecosystem.txt (never printed). Use -Reissue for a new temporary password.

  The order matters: OptiFleet gets its tenant before OptiDeli announces the vehicles, and OptiStash goes last so that its pickup
  request finds OptiDeli ready.

  .\seed-demo.ps1
  .\seed-demo.ps1 -Reissue
#>
param([switch]$Reissue)

$ErrorActionPreference = 'Continue'   # native commands write warnings to stderr; the exit codes are checked instead
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$state = Join-Path $here '.state'
$allow = 'ECOSYSTEM_ALLOW_DEMO_SEED=true'

function Invoke-Container([string]$Name, [string]$Command) {
    $output = & podman exec $Name sh -c $Command
    $output | ForEach-Object { Write-Host "   $_" }
    return @{ Code = $LASTEXITCODE; Output = ($output -join "`n") }
}

function Invoke-WithRetry([string]$Label, [string]$Name, [string]$Command) {
    for ($try = 1; $try -le 12; $try++) {
        $r = Invoke-Container $Name $Command
        if ($r.Code -eq 0) { return $r }
        Write-Host "   not ready yet in $Label; waiting ($try/12)"
        Start-Sleep -Seconds 5
    }
    throw "ecosystem:demo-data did not finish in $Label."
}

Write-Host '== OptiNexus: customer, tenant, subscription, administrator'
$extra = ''
if ($Reissue) { $extra = ' --reissue' }
$nexus = Invoke-Container 'optiecosystem-nexus-api-1' "$allow php artisan ecosystem:seed-demo --credentials-file=/tmp/demo-ecosystem.txt$extra"
if ($nexus.Code -ne 0) { throw 'ecosystem:seed-demo failed in OptiNexus.' }
$tenantId = ''
if ($nexus.Output -match 'NEXUS_TENANT_ID=([0-9a-f-]{36})') { $tenantId = $Matches[1] }
if ($tenantId -eq '') { throw 'OptiNexus did not report the tenant id.' }
& podman exec optiecosystem-nexus-api-1 test -f /tmp/demo-ecosystem.txt
if ($LASTEXITCODE -eq 0) {
    & podman cp optiecosystem-nexus-api-1:/tmp/demo-ecosystem.txt (Join-Path $state 'demo-ecosystem.txt') | Out-Null
    & podman exec optiecosystem-nexus-api-1 rm -f /tmp/demo-ecosystem.txt | Out-Null
    Write-Host "   administrator credential: $(Join-Path $state 'demo-ecosystem.txt')"
}

Write-Host '== OptiFleet: tenant, branches, workshops'
Invoke-WithRetry 'OptiFleet' 'optiecosystem-fleet-api-1' "$allow php artisan ecosystem:demo-data --nexus-tenant-id=$tenantId" | Out-Null

Write-Host '== OptiEntry: organization and demo books'
Invoke-WithRetry 'OptiEntry' 'optiecosystem-entry-api-1' "$allow php artisan ecosystem:demo-data --nexus-tenant-id=$tenantId" | Out-Null

Write-Host '== OptiDeli: demo data (announces the vehicles to OptiFleet and OptiRadar)'
Invoke-WithRetry 'OptiDeli' 'optiecosystem-deli-api-1' "$allow php artisan ecosystem:demo-data" | Out-Null

Write-Host '== OptiStash: demo data (sends a pickup request to OptiDeli)'
Invoke-WithRetry 'OptiStash' 'optiecosystem-stash-api-1' "$allow php artisan ecosystem:demo-data" | Out-Null

Write-Host ''
Write-Host 'Done. Sign in with the administrator in .state\demo-ecosystem.txt (single sign-on, choose a new password on first use).'
