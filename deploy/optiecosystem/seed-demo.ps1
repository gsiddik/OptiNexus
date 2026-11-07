<#
.SYNOPSIS
  Loads the demo tenant of the whole ecosystem: OptiNexus first (customer, tenant, suite subscription, administrator), then the demo
  data of every application that has any (OptiDeli, OptiStash), in order, so that what one platform creates reaches the others through
  the real OptiNexus events (vehicles to OptiRadar, the pickup request to OptiDeli, ...).

.DESCRIPTION
  Local installations only. Safe to run again: what exists is kept. The administrator's temporary password is written to
  .state\demo-ecosystem.txt (never printed). Use -Reissue for a new temporary password.

  OptiFleet and OptiEntry create the tenant on the administrator's first single sign-on; run the script again after that first sign-in
  (the demo data of those two products is not part of this version).

  .\seed-demo.ps1
  .\seed-demo.ps1 -Reissue
#>
param([switch]$Reissue)

$ErrorActionPreference = 'Continue'   # native commands write warnings to stderr; the exit codes are checked instead
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$state = Join-Path $here '.state'
$allow = 'ECOSYSTEM_ALLOW_DEMO_SEED=true'

function Invoke-Container([string]$Name, [string]$Command) {
    & podman exec $Name sh -c $Command
    return $LASTEXITCODE
}

Write-Host '== OptiNexus: customer, tenant, subscription, administrator'
$extra = ''
if ($Reissue) { $extra = ' --reissue' }
$code = Invoke-Container 'optiecosystem-nexus-api-1' "$allow php artisan ecosystem:seed-demo --credentials-file=/tmp/demo-ecosystem.txt$extra"
if ($code -ne 0) { throw 'ecosystem:seed-demo failed in OptiNexus.' }
& podman cp optiecosystem-nexus-api-1:/tmp/demo-ecosystem.txt (Join-Path $state 'demo-ecosystem.txt') | Out-Null
if ($LASTEXITCODE -eq 0) {
    & podman exec optiecosystem-nexus-api-1 rm -f /tmp/demo-ecosystem.txt | Out-Null
    Write-Host "   administrator credential: $(Join-Path $state 'demo-ecosystem.txt')"
}

foreach ($product in @(@('deli', 'OptiDeli'), @('stash', 'OptiStash'))) {
    Write-Host "== $($product[1]): demo data"
    $done = $false
    for ($try = 1; $try -le 12 -and -not $done; $try++) {
        $code = Invoke-Container "optiecosystem-$($product[0])-api-1" "$allow php artisan ecosystem:demo-data"
        if ($code -eq 0) { $done = $true } else { Write-Host "   the tenant is not in $($product[1]) yet; waiting ($try/12)"; Start-Sleep -Seconds 5 }
    }
    if (-not $done) { throw "ecosystem:demo-data did not finish in $($product[1])." }
}

Write-Host ''
Write-Host 'Done. Sign in with the administrator in .state\demo-ecosystem.txt (single sign-on, choose a new password on first use).'
Write-Host 'OptiFleet and OptiEntry create the tenant at the first sign-in; the vehicles of OptiDeli reach OptiRadar already.'
