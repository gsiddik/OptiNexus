#Requires -Version 5.1
<#
.SYNOPSIS
  OptiEcosystem: runs the Opti platform (OptiNexus always, plus any of OptiFleet, OptiRadar, OptiEntry, OptiDeli) on one machine with
  Podman (Podman Desktop) or Docker.

.DESCRIPTION
  One script for Windows (Podman Desktop / Docker Desktop), Linux and macOS (PowerShell 7: pwsh optiecosystem.ps1 <command>).
  Every product keeps its own Compose project and volumes (defined in its own repository); this script only adds what they share:
  a network, public host names that resolve to the same URL for the browser and for the other containers, and per-product env files.

  Commands:  sync | preflight | init | build | up | status | smoke | stop | start | down | backup | restore | upgrade | manifest | rollback-config
  Products:  -Products fleet,radar,entry,deli   (any subset; OptiNexus is always included; default = all four)

  Safety: nothing here deletes a volume or an env file, and no command prints a secret. `down` removes containers and the network only.
  Generated settings and secrets live in deploy/optiecosystem/.state (never committed); backups in deploy/optiecosystem/backups.
  An installation made before the rename (deploy/ecosystem) is picked up: its .state is copied here once, the original stays untouched.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('help', 'sync', 'preflight', 'init', 'build', 'up', 'status', 'smoke', 'stop', 'start', 'down', 'backup', 'restore', 'upgrade', 'manifest', 'rollback-config')]
    [string]$Command = 'help',
    [string[]]$Products = @('fleet', 'radar', 'entry', 'deli'),
    [switch]$Build,
    [string]$Backup,
    [string]$To,
    [switch]$ConfirmReplace,
    # sync only: switch every selected repository that has this branch on origin to it (for example main). Without it each one stays
    # on the branch it is on.
    [string]$Branch
)

$ErrorActionPreference = 'Stop'
$Here = $PSScriptRoot
$State = Join-Path $Here '.state'
$Backups = Join-Path $Here 'backups'
$LegacyState = Join-Path (Join-Path (Split-Path $Here -Parent) 'ecosystem') '.state'
$Root = if ($env:OPTI_ROOT) { $env:OPTI_ROOT } else { (Resolve-Path (Join-Path $Here '../../..')).Path }

# --- catalog: add a product here (and an overlay + env template) to make it available; no combination is hard-coded ----------------------
$Catalog = [ordered]@{
    nexus = @{ Title = 'OptiNexus'; Dirs = @('OptiNexus'); Overlay = 'nexus.yaml'; PhpService = 'api'; Postgres = 'postgres'
        Urls = @(@{ Name = 'API'; Port = 'NEXUS_API_PORT'; Path = '/up' }, @{ Name = 'Console'; Port = 'NEXUS_UI_PORT'; Path = '/nginx-health' }); Public = 'NEXUS_PUBLIC_URL' }
    fleet = @{ Title = 'OptiFleet'; Dirs = @('OptiFleet-v2', 'OptiFleet'); Overlay = 'fleet.yaml'; PhpService = 'api'; Postgres = 'postgres'; Mongo = 'mongo'; Storage = '/var/www/html/storage/app'
        Urls = @(@{ Name = 'API'; Port = 'FLEET_API_PORT'; Path = '/up' }, @{ Name = 'Console'; Port = 'FLEET_UI_PORT'; Path = '/nginx-health' }); Public = 'FLEET_PUBLIC_URL' }
    entry = @{ Title = 'OptiEntry'; Dirs = @('OptiAccounting', 'OptiEntry'); Overlay = 'entry.yaml'; PhpService = 'api'; Postgres = 'postgres'; Storage = '/var/www/html/storage/app'
        Urls = @(@{ Name = 'API'; Port = 'ENTRY_API_PORT'; Path = '/up' }, @{ Name = 'Console'; Port = 'ENTRY_UI_PORT'; Path = '/nginx-health' }); Public = 'ENTRY_PUBLIC_URL' }
    deli = @{ Title = 'OptiDeli'; Dirs = @('OptiDeli'); Overlay = 'deli.yaml'; ComposeFile = 'docker-compose.container.yml'; PhpService = 'api'; Postgres = 'postgres'; Storage = '/var/www/html/storage/app'
        Urls = @(@{ Name = 'API'; Port = 'DELI_API_PORT'; Path = '/up' }, @{ Name = 'Console'; Port = 'DELI_UI_PORT'; Path = '/nginx-health' }); Public = 'DELI_PUBLIC_URL' }
    radar = @{ Title = 'OptiRadar'; Dirs = @('OptiRadar', 'optiradar'); WebDirs = @('OptiRadar-web', 'optiradar-web'); Overlay = 'radar.yaml'; PhpService = $null; Postgres = 'database'
        Urls = @(@{ Name = 'Server + Web'; Port = 'RADAR_PORT'; Path = '/api/health' }); Public = 'RADAR_PUBLIC_URL' }
}
# Repositories fetched by `sync` (folder name = GitHub repository name). OPTI_GIT_BASE overrides the base URL, for example a fork.
$Repos = [ordered]@{ nexus = 'OptiNexus'; fleet = 'OptiFleet-v2'; entry = 'OptiAccounting'; radar = 'OptiRadar'; 'radar-web' = 'OptiRadar-web'; deli = 'OptiDeli' }
$GitBase = if ($env:OPTI_GIT_BASE) { $env:OPTI_GIT_BASE.TrimEnd('/') } else { 'https://github.com/gsiddik' }
$MinMemoryMb = @{ nexus = 1024; fleet = 2560; entry = 1280; radar = 1536; deli = 768 }
# Every product keeps its compose file in its own repository; docker-compose.yml unless the catalog names another one.
function Get-ComposeFile([string]$Product) { if ($Catalog[$Product].ComposeFile) { return $Catalog[$Product].ComposeFile } return 'docker-compose.yml' }

# One-time pick-up of the settings of an installation made before the folder was renamed (deploy/ecosystem -> deploy/optiecosystem).
# Copy, never move: the old folder keeps its files, so going back to an older checkout still works.
function Import-LegacyState {
    if ((Test-Path $State) -or -not (Test-Path $LegacyState)) { return }
    Copy-Item -LiteralPath $LegacyState -Destination $State -Recurse
    if ($PSVersionTable.PSVersion.Major -ge 6 -and -not $IsWindows) { & chmod -R go-rwx $State 2>$null }
    Write-Host "Settings of the existing installation copied from $LegacyState (the original is kept). Compose projects, volumes and the network keep their names, so no data moves."
}

# --- output helpers ---------------------------------------------------------------------------------------------------------------------
$script:Results = New-Object System.Collections.ArrayList
function Add-Result([string]$Level, [string]$Check, [string]$Detail) {
    [void]$script:Results.Add([pscustomobject]@{ Level = $Level; Check = $Check; Detail = $Detail })
    $color = switch ($Level) { 'PASS' { 'Green' } 'WARN' { 'Yellow' } 'FAIL' { 'Red' } default { 'Gray' } }
    Write-Host ("[{0}] {1}: {2}" -f $Level, $Check, $Detail) -ForegroundColor $color
}
function Say([string]$Text) { Write-Host $Text }
function Stop-Script([string]$Text) { Write-Host "ERROR: $Text" -ForegroundColor Red; exit 1 }
function Complete-Checks {
    $fails = @($script:Results | Where-Object { $_.Level -eq 'FAIL' }).Count
    $warns = @($script:Results | Where-Object { $_.Level -eq 'WARN' }).Count
    Say ("{0} failed, {1} warnings." -f $fails, $warns)
    if ($fails -gt 0) { exit 1 }
}

# OptiNexus is always part of preflight/up/smoke/backup. stop/start/down touch it only when it is named (or with -Products all), so one
# product can be taken down while the others and OptiNexus keep running.
function Resolve-Products([switch]$NexusOnlyWhenNamed) {
    $list = @()
    foreach ($p in $Products) { foreach ($x in ($p -split ',')) { $x = $x.Trim().ToLowerInvariant(); if ($x) { $list += $x } } }
    if ($list -contains 'all') { $list = @($Catalog.Keys) }
    foreach ($x in $list) { if (-not $Catalog.Contains($x)) { Stop-Script "Unknown product '$x'. Known: all, $($Catalog.Keys -join ', ')" } }
    $named = @($list | Where-Object { $_ -ne 'nexus' } | Select-Object -Unique)
    if ($NexusOnlyWhenNamed -and -not ($list -contains 'nexus')) { return $named }
    return @('nexus') + $named
}

# --- container engine and Compose provider ---------------------------------------------------------------------------------------------
function Find-Exe([string]$Name) { $c = Get-Command $Name -ErrorAction SilentlyContinue | Select-Object -First 1; if ($c) { return $c.Source } return $null }

function Invoke-Quiet([string]$Exe, [string[]]$ExeArgs) {
    $out = & $Exe @ExeArgs 2>&1
    return [pscustomobject]@{ Code = $LASTEXITCODE; Text = (($out | ForEach-Object { "$_" }) -join "`n") }
}

$script:Engine = $null
function Get-Engine {
    if ($script:Engine) { return $script:Engine }
    $want = $env:ECOSYSTEM_ENGINE
    $podman = Find-Exe 'podman'; $docker = Find-Exe 'docker'
    $name = $null
    if ($want) { $name = $want } elseif ($podman) { $name = 'podman' } elseif ($docker) { $name = 'docker' }
    if (-not $name) { return $null }
    $exe = if ($name -eq 'podman') { $podman } else { $docker }
    if (-not $exe) { return $null }
    $compose = $null; $provider = ''
    if ($env:ECOSYSTEM_COMPOSE) {
        $compose = @($env:ECOSYSTEM_COMPOSE -split ' '); $provider = 'ECOSYSTEM_COMPOSE'
    } elseif ($name -eq 'docker') {
        $r = Invoke-Quiet $exe @('compose', 'version')
        if ($r.Code -eq 0) { $compose = @($exe, 'compose'); $provider = 'docker compose' }
    } else {
        $dc = Find-Exe 'docker-compose'
        $r = Invoke-Quiet $exe @('compose', 'version')
        if ($r.Code -eq 0) { $compose = @($exe, 'compose'); $provider = "podman compose ($(($r.Text -split "`n" | Where-Object { $_ -match 'compose' } | Select-Object -First 1)))" }
        elseif ($dc) { $compose = @($dc); $provider = 'docker-compose (against the Podman socket)' }
        else { $pc = Find-Exe 'podman-compose'; if ($pc) { $compose = @($pc); $provider = 'podman-compose (limited: check the runbook)' } }
    }
    $script:Engine = @{ Name = $name; Exe = $exe; Compose = $compose; Provider = $provider }
    return $script:Engine
}

function Get-RepoDir([string]$Product, [switch]$Web) {
    $def = $Catalog[$Product]
    $names = if ($Web) { $def.WebDirs } else { $def.Dirs }
    if (-not (Test-Path $Root)) { return $null }
    $dirs = Get-ChildItem -Path $Root -Directory -Force -ErrorAction SilentlyContinue
    foreach ($n in $names) { $hit = $dirs | Where-Object { $_.Name -ieq $n } | Select-Object -First 1; if ($hit) { return $hit.FullName } }
    return $null
}

function Get-GitInfo([string]$Dir) {
    if (-not $Dir -or -not (Find-Exe 'git')) { return $null }
    $sha = (& git -C $Dir rev-parse --short=12 HEAD 2>$null)
    if ($LASTEXITCODE -ne 0) { return $null }
    $branch = (& git -C $Dir rev-parse --abbrev-ref HEAD 2>$null)
    $dirty = [bool](& git -C $Dir status --porcelain 2>$null)
    return [pscustomobject]@{ Sha = "$sha"; Branch = "$branch"; Dirty = $dirty }
}

# --- env files --------------------------------------------------------------------------------------------------------------------------
function Read-EnvFile([string]$Path) {
    $h = [ordered]@{}
    if (-not (Test-Path $Path)) { return $h }
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*#' -or $line -notmatch '=') { continue }
        $i = $line.IndexOf('='); $h[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1)
    }
    return $h
}
function Write-EnvFile([string]$Path, $Values, [string]$Header) {
    $lines = @("# $Header", "# Generated by deploy/optiecosystem/optiecosystem.ps1 init. Contains secrets: never commit or share it. Edit values freely; init only adds missing keys.")
    foreach ($k in $Values.Keys) { $lines += "$k=$($Values[$k])" }
    [System.IO.File]::WriteAllText($Path, (($lines -join "`n") + "`n"), (New-Object System.Text.UTF8Encoding($false)))
    if ($PSVersionTable.PSVersion.Major -ge 6 -and -not $IsWindows) { & chmod 600 $Path 2>$null }
}
function New-RandomBytes([int]$Count) {
    $b = New-Object byte[] $Count
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create(); $rng.GetBytes($b); $rng.Dispose()
    return $b
}
function New-AppKey { return 'base64:' + [Convert]::ToBase64String((New-RandomBytes 32)) }
function New-Password { return ([Convert]::ToBase64String((New-RandomBytes 24))).TrimEnd('=').Replace('+', 'A').Replace('/', 'b') }
# For password rules that ask for upper and lower case, a digit and a symbol (OptiDeli platform administrator).
function New-AdminPassword { return (New-Password) + 'aZ7!' }

function Get-Eco { return Read-EnvFile (Join-Path $State 'ecosystem.env') }
function Get-ProductEnv([string]$Product) { return Read-EnvFile (Join-Path $State "$Product.env") }

function Get-Defaults([string]$Product, $Eco) {
    $d = $Eco['ECOSYSTEM_DOMAIN']; $bind = $Eco['BIND_ADDRESS']
    $nexusUrl = "http://nexus.$d`:$($Eco['NEXUS_API_PORT'])"
    $common = [ordered]@{ ECOSYSTEM_DOMAIN = $d; ECOSYSTEM_NETWORK = $Eco['ECOSYSTEM_NETWORK']; BIND_ADDRESS = $bind }
    switch ($Product) {
        'nexus' { return [ordered]@{} + $common + [ordered]@{
                APP_KEY = (New-AppKey); DB_PASSWORD = (New-Password); NEXUS_PUBLIC_URL = $nexusUrl; OIDC_REQUIRE_PUBLIC_ISSUER = 'true'
                NEXUS_API_PORT = $Eco['NEXUS_API_PORT']; NEXUS_UI_PORT = $Eco['NEXUS_UI_PORT']
                NEXUS_CONSOLE_URL = "http://nexus-console.$d`:$($Eco['NEXUS_UI_PORT'])"
                CGO_SUPERADMIN_EMAIL = 'superadmin@cgo.local'; CGO_SUPERADMIN_PASSWORD = (New-Password); NEXUS_SEED = 'catalog'
                OPTIRADAR_SYNC_ENABLED = 'false'; OPTIRADAR_BASE_URL = "http://radar.$d`:$($Eco['RADAR_PORT'])"; OPTIRADAR_EMBED_TOKEN = '' } }
        'fleet' { return [ordered]@{} + $common + [ordered]@{
                APP_KEY = (New-AppKey); DB_PASSWORD = (New-Password); FLEET_PUBLIC_URL = "http://fleet.$d`:$($Eco['FLEET_UI_PORT'])"
                FLEET_API_PORT = $Eco['FLEET_API_PORT']; FLEET_UI_PORT = $Eco['FLEET_UI_PORT']; FLEET_SEED = 'catalog'
                OPTINEXUS_ENABLED = 'true'; OPTINEXUS_BASE_URL = $nexusUrl; OPTINEXUS_SSO_CLIENT_ID = ''; OPTINEXUS_SSO_CLIENT_SECRET = ''
                OPTINEXUS_EVENTS_ENABLED = 'false'; OPTINEXUS_GATEWAY_CLIENT_ID = ''; OPTINEXUS_GATEWAY_CLIENT_SECRET = '' } }
        'entry' { return [ordered]@{} + $common + [ordered]@{
                APP_KEY = (New-AppKey); DB_PASSWORD = (New-Password); ENTRY_PUBLIC_URL = "http://entry.$d`:$($Eco['ENTRY_UI_PORT'])"
                ENTRY_API_PORT = $Eco['ENTRY_API_PORT']; ENTRY_UI_PORT = $Eco['ENTRY_UI_PORT']
                OPTIENTRY_IDENTITY_MODE = 'optinexus'; OPTINEXUS_BASE_URL = $nexusUrl; OPTINEXUS_APPLICATION_CODE = 'optiaccounting'
                OPTINEXUS_SSO_CLIENT_ID = ''; OPTINEXUS_SSO_CLIENT_SECRET = ''; OPTINEXUS_SERVICE_CLIENT_ID = ''; OPTINEXUS_SERVICE_CLIENT_SECRET = '' } }
        'radar' { return [ordered]@{} + $common + [ordered]@{
                RADAR_DB_PASSWORD = (New-Password); RADAR_PUBLIC_URL = "http://radar.$d`:$($Eco['RADAR_PORT'])"; RADAR_PORT = $Eco['RADAR_PORT']
                OPTINEXUS_BASE_URL = $nexusUrl; OPTINEXUS_SSO_CLIENT_ID = ''; OPTINEXUS_SSO_CLIENT_SECRET = ''; OPTINEXUS_EVENTS_ENABLE = 'false'
                OPTINEXUS_SERVICE_CLIENT_ID = ''; OPTINEXUS_SERVICE_CLIENT_SECRET = ''; OPTINEXUS_ENTITLEMENTS_ENFORCE = 'false'
                WEB_FRAME_ANCESTORS = "http://deli.$d`:$($Eco['DELI_UI_PORT'])" } }
        # Local trial defaults: APP_ENV=local plus OPTIDELI_SEED=demo gives the demo tenant and accounts (DemoSeeder refuses anything else).
        # Identity stays local until OptiDeli is registered in OptiNexus; the service account alone is enough for the OptiRadar map.
        'deli' { return [ordered]@{} + $common + [ordered]@{
                APP_ENV = 'local'; APP_KEY = (New-AppKey); DB_PASSWORD = (New-Password); DELI_PUBLIC_URL = "http://deli.$d`:$($Eco['DELI_UI_PORT'])"
                DELI_API_PORT = $Eco['DELI_API_PORT']; DELI_UI_PORT = $Eco['DELI_UI_PORT']
                OPTIDELI_DEPLOYMENT_MODE = 'standalone'; OPTIDELI_STANDALONE_TENANT = 'demo'; OPTIDELI_IDENTITY_MODE = 'local'
                OPTIDELI_SEED = 'demo'; OPTIDELI_DEMO_PASSWORD = (New-Password)
                OPTIDELI_PLATFORM_ADMIN_EMAIL = 'superadmin@optideli.local'; OPTIDELI_PLATFORM_ADMIN_PASSWORD = (New-AdminPassword)
                OPTINEXUS_BASE_URL = $nexusUrl; OPTINEXUS_SSO_CLIENT_ID = ''; OPTINEXUS_SSO_CLIENT_SECRET = ''
                OPTINEXUS_SERVICE_CLIENT_ID = ''; OPTINEXUS_SERVICE_CLIENT_SECRET = ''; OPTINEXUS_FEED_PAIRING = 'auto' } }
    }
}

function Save-History {
    if (-not (Test-Path $State)) { return }
    $files = Get-ChildItem -Path $State -Filter '*.env' -File -ErrorAction SilentlyContinue
    if (-not $files) { return }
    $dest = Join-Path (Join-Path $State 'history') (Get-Date -Format 'yyyyMMdd-HHmmss')
    New-Item -ItemType Directory -Path $dest -Force | Out-Null
    foreach ($f in $files) { Copy-Item -LiteralPath $f.FullName -Destination $dest }
    if ($PSVersionTable.PSVersion.Major -ge 6 -and -not $IsWindows) { & chmod -R go-rwx $dest 2>$null }
    return $dest
}

# --- compose --------------------------------------------------------------------------------------------------------------------------
# The compose arguments that select one product's project: its own compose file, the shared overlay and, when present, a site-specific
# .state/<product>.override.yaml (registry mirrors, resource limits, ...). COMPOSE_PROJECT_NAME in .state/<product>.env renames the project.
function Get-ComposeArgs([string]$Product, [string[]]$ComposeArgs) {
    $eng = Get-Engine
    if (-not $eng -or -not $eng.Compose) { Stop-Script 'No Compose provider found. Run: optiecosystem.ps1 preflight' }
    $dir = Get-RepoDir $Product
    if (-not $dir) { Stop-Script "Repository for $($Catalog[$Product].Title) not found under $Root (looked for: $($Catalog[$Product].Dirs -join ', '))." }
    $envFile = Join-Path $State "$Product.env"
    if (-not (Test-Path $envFile)) { Stop-Script "Missing $envFile. Run: optiecosystem.ps1 init" }
    $overlay = Join-Path (Join-Path $Here 'overlays') $Catalog[$Product].Overlay
    $prefix = @(); if ($eng.Compose.Count -gt 1) { $prefix = $eng.Compose[1..($eng.Compose.Count - 1)] }
    $files = @('-f', (Join-Path $dir (Get-ComposeFile $Product)), '-f', $overlay)
    $site = Join-Path $State "$Product.override.yaml"
    if (Test-Path $site) { $files += @('-f', $site) }
    return $prefix + @('--project-directory', $dir, '--env-file', $envFile) + $files + $ComposeArgs
}

function Invoke-Compose([string]$Product, [string[]]$ComposeArgs, [switch]$Quiet) {
    $eng = Get-Engine
    $all = Get-ComposeArgs $Product $ComposeArgs
    if ($Quiet) { $out = & $eng.Compose[0] @all 2>&1; return [pscustomobject]@{ Code = $LASTEXITCODE; Text = (($out | ForEach-Object { "$_" }) -join "`n") } }
    & $eng.Compose[0] @all
    if ($LASTEXITCODE -ne 0) { Stop-Script "$($Catalog[$Product].Title): compose $($ComposeArgs -join ' ') failed (exit $LASTEXITCODE)." }
}

function Ensure-Network {
    $eng = Get-Engine; $eco = Get-Eco; $net = $eco['ECOSYSTEM_NETWORK']
    $r = Invoke-Quiet $eng.Exe @('network', 'inspect', $net)
    if ($r.Code -ne 0) {
        $c = Invoke-Quiet $eng.Exe @('network', 'create', $net)
        if ($c.Code -ne 0) { Stop-Script "Cannot create network ${net}: $($c.Text)" }
        Say "Created network $net."
    }
}

function Test-Url([string]$Url, [int]$TimeoutSec = 5) {
    try {
        $p = @{ Uri = $Url; TimeoutSec = $TimeoutSec; UseBasicParsing = $true; ErrorAction = 'Stop' }
        $r = Invoke-WebRequest @p
        return [int]$r.StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode }
        return 0
    }
}
function Wait-Url([string]$Url, [int]$Seconds) {
    $end = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $end) { if ((Test-Url $Url 5) -eq 200) { return $true }; Start-Sleep -Seconds 3 }
    return $false
}
function Get-LocalUrl([string]$Product, $Url) {
    $penv = Get-ProductEnv $Product
    $bind = if ($penv['BIND_ADDRESS'] -and $penv['BIND_ADDRESS'] -ne '0.0.0.0') { $penv['BIND_ADDRESS'] } else { '127.0.0.1' }
    $port = $penv[$Url.Port]; if (-not $port) { $port = (Get-Eco)[$Url.Port] }
    return "http://${bind}:$port$($Url.Path)"
}

# --- commands -------------------------------------------------------------------------------------------------------------------------
# sync: clone the repositories that are missing next to OptiNexus and fast-forward the others. Never discards work: a repository with
# uncommitted changes is skipped, and only fast-forward pulls are made (a diverged branch is reported, not merged or reset).
# up/build never touch Git; run sync (or git pull yourself) first, then upgrade or build + up.
function Invoke-Sync {
    if (-not (Find-Exe 'git')) { Stop-Script 'git not found on PATH (winget install Git.Git)' }
    $names = @()
    foreach ($p in @(Resolve-Products)) { $names += $p; if ($p -eq 'radar') { $names += 'radar-web' } }
    foreach ($n in $names) {
        $folder = $Repos[$n]
        $dir = if ($n -eq 'radar-web') { Get-RepoDir 'radar' -Web } else { Get-RepoDir $n }
        if (-not $dir) {
            $dir = Join-Path $Root $folder
            Say "== clone $folder"
            # core.autocrlf=false: the shell scripts run inside Linux containers and need LF line ends, also on Windows.
            & git clone -c core.autocrlf=false "$GitBase/$folder.git" $dir
            if ($LASTEXITCODE -ne 0) { Add-Result FAIL "Sync $folder" "git clone $GitBase/$folder.git failed"; continue }
        }
        if (& git -C $dir status --porcelain) { Add-Result WARN "Sync $folder" 'uncommitted changes: left as it is (commit or stash them, then sync again)'; continue }
        Say "== fetch $folder"
        & git -C $dir fetch --prune origin
        if ($LASTEXITCODE -ne 0) { Add-Result FAIL "Sync $folder" 'git fetch failed (network or credentials)'; continue }
        if ($Branch) {
            & git -C $dir rev-parse --verify --quiet "refs/remotes/origin/$Branch" *> $null
            if ($LASTEXITCODE -eq 0) {
                & git -C $dir checkout $Branch
                if ($LASTEXITCODE -ne 0) { Add-Result FAIL "Sync $folder" "git checkout $Branch failed"; continue }
            } else { Add-Result WARN "Sync $folder" "origin has no branch ${Branch}: staying on the current branch" }
        }
        & git -C $dir pull --ff-only
        $pullCode = $LASTEXITCODE
        $g = Get-GitInfo $dir
        if ($pullCode -ne 0) { Add-Result FAIL "Sync $folder" "pull --ff-only refused on $($g.Branch) (diverged or no upstream): nothing was changed" }
        else { Add-Result PASS "Sync $folder" "$($g.Branch) $($g.Sha)" }
    }
    Say 'Next: optiecosystem.ps1 upgrade -Products <list> (backup, rebuild, re-create), or build then up for a first installation.'
    Complete-Checks
}

function Invoke-Preflight {
    $sel = @(Resolve-Products)
    Say "Preflight for: $($sel -join ', ')  (repositories under $Root)"
    if ($PSVersionTable.PSVersion.Major -ge 7) { Add-Result PASS 'PowerShell' "$($PSVersionTable.PSVersion)" } else { Add-Result WARN 'PowerShell' "$($PSVersionTable.PSVersion): works, PowerShell 7 recommended (winget install Microsoft.PowerShell)" }

    $eng = Get-Engine
    if (-not $eng) { Add-Result FAIL 'Container engine' 'neither podman nor docker found on PATH'; Complete-Checks; return }
    $v = Invoke-Quiet $eng.Exe @('--version'); Add-Result PASS 'Container engine' "$($eng.Name): $($v.Text.Trim())"
    $info = Invoke-Quiet $eng.Exe @('info', '--format', 'json')
    if ($info.Code -ne 0) {
        $hint = if ($eng.Name -eq 'podman') { 'podman machine list; podman machine start; podman system connection list' } else { 'start Docker Desktop / the docker service' }
        Add-Result FAIL 'Engine reachable' "$($eng.Name) info failed ($hint). $(($info.Text -split "`n" | Select-Object -First 2) -join ' ')"; Complete-Checks; return
    }
    Add-Result PASS 'Engine reachable' 'info answered'
    $cpus = 0; $memMb = 0; $rootless = $null
    try {
        $j = $info.Text | ConvertFrom-Json
        if ($eng.Name -eq 'podman') { $cpus = [int]$j.host.cpus; $memMb = [int]([double]$j.host.memTotal / 1MB); $rootless = $j.host.security.rootless; $sock = $j.host.remoteSocket.path }
        else { $cpus = [int]$j.NCPU; $memMb = [int]([double]$j.MemTotal / 1MB) }
    } catch { Add-Result WARN 'Engine info' 'could not parse the engine info; resource checks skipped' }
    if ($eng.Name -eq 'podman') {
        $mach = Invoke-Quiet $eng.Exe @('machine', 'list', '--format', 'json')
        if ($mach.Code -eq 0 -and $mach.Text.Trim().StartsWith('[')) {
            $ml = @($mach.Text | ConvertFrom-Json)
            if ($ml.Count -eq 0) { Add-Result WARN 'Podman machine' 'no machine listed (fine on native Linux); on Windows/macOS run: podman machine init; podman machine start' }
            else { $run = @($ml | Where-Object { $_.Running }); if ($run.Count -gt 0) { Add-Result PASS 'Podman machine' "$($run[0].Name) running ($($run[0].CPUs) CPU)" } else { Add-Result FAIL 'Podman machine' 'a machine exists but none is running: podman machine start' } }
        }
        $conn = Invoke-Quiet $eng.Exe @('system', 'connection', 'list', '--format', 'json')
        if ($conn.Code -eq 0 -and $conn.Text.Trim().StartsWith('[')) { $cl = @($conn.Text | ConvertFrom-Json); $def = @($cl | Where-Object { $_.Default }); if ($def.Count -gt 0) { Add-Result PASS 'Podman connection' "default: $($def[0].Name)" } elseif ($cl.Count -gt 0) { Add-Result WARN 'Podman connection' 'no default connection: podman system connection default <name>' } }
        if ($null -ne $rootless) { Add-Result PASS 'Rootless mode' "rootless=$rootless (neither assumed nor required; ports >= 1024 only)" }
    }
    if (-not $eng.Compose) { Add-Result FAIL 'Compose provider' 'none found (docker compose v2, podman compose with docker-compose, or podman-compose)' }
    else {
        $cv = Invoke-Quiet $eng.Compose[0] (@($(if ($eng.Compose.Count -gt 1) { $eng.Compose[1..($eng.Compose.Count - 1)] }) ) + @('version'))
        Add-Result PASS 'Compose provider' "$($eng.Provider): $(($cv.Text -split "`n" | Select-Object -First 1).Trim())"
    }
    if ($memMb -gt 0) {
        $need = 512; foreach ($p in $sel) { $need += $MinMemoryMb[$p] }
        if ($memMb -lt $need) { Add-Result WARN 'Memory' "${memMb} MB available to the engine, about ${need} MB advised for $($sel -join '+')$(if ($eng.Name -eq 'podman') { ' (podman machine set --memory ' + $need + ')' })" } else { Add-Result PASS 'Memory' "${memMb} MB (advised ${need} MB)" }
        if ($cpus -lt 2) { Add-Result WARN 'CPU' "$cpus CPU; 4 advised" } else { Add-Result PASS 'CPU' "$cpus CPU" }
    }

    foreach ($p in $sel) {
        $dir = Get-RepoDir $p
        if (-not $dir) { Add-Result FAIL "Repository $p" "not found under $Root (looked for $($Catalog[$p].Dirs -join ', '); set OPTI_ROOT)"; continue }
        $g = Get-GitInfo $dir
        $detail = if ($g) { "$dir @ $($g.Branch) $($g.Sha)$(if ($g.Dirty) { ' (uncommitted changes)' })" } else { "$dir (not a git checkout)" }
        Add-Result PASS "Repository $p" $detail
        $cf = Get-ComposeFile $p
        if (-not (Test-Path (Join-Path $dir $cf))) { Add-Result FAIL "Compose file $p" "$cf missing: this branch has no container package" }
    }
    if ($sel -contains 'radar') {
        $web = Get-RepoDir 'radar' -Web
        if ($web) { Add-Result PASS 'Repository radar-web' $web } else { Add-Result WARN 'Repository radar-web' 'sibling checkout not found; the build falls back to the optiradar-web submodule (git submodule update --init)' }
        if ($eng.Name -eq 'podman') { $pv = ($v.Text -replace '[^\d\.]', ' ').Trim().Split(' ')[0]; try { if ([version]$pv -lt [version]'4.5') { Add-Result WARN 'Podman version' "${pv}: --build-context needs 4.5 or newer for the OptiRadar build" } } catch { } }
    }

    $ecoFile = Join-Path $State 'ecosystem.env'
    if (-not (Test-Path $ecoFile)) { Add-Result WARN 'Settings' 'not initialised yet: run optiecosystem.ps1 init' }
    else {
        $eco = Get-Eco
        foreach ($p in $sel) {
            $penv = Get-ProductEnv $p
            if ($penv.Count -eq 0) { Add-Result WARN "Env $p" "missing $p.env: run optiecosystem.ps1 init"; continue }
            $keyName = if ($p -eq 'radar') { 'RADAR_DB_PASSWORD' } else { 'DB_PASSWORD' }
            if (-not $penv[$keyName]) { Add-Result FAIL "Env $p" "$keyName is empty" }
            if ($p -ne 'radar' -and -not $penv['APP_KEY']) { Add-Result FAIL "Env $p" 'APP_KEY is empty' }
        }
        $issuer = (Get-ProductEnv 'nexus')['NEXUS_PUBLIC_URL']
        if (-not $issuer) { Add-Result FAIL 'Issuer URL' 'NEXUS_PUBLIC_URL is empty' }
        elseif ($issuer -match '//(localhost|127\.0\.0\.1|\[::1\])([:/]|$)') { Add-Result FAIL 'Issuer URL' "$issuer is the loopback address: other containers would resolve it to themselves" }
        elseif ($issuer -match '/$|//[^/]+/.+') { Add-Result FAIL 'Issuer URL' "$issuer must be an origin only (no path, no trailing slash)" }
        else { Add-Result PASS 'Issuer URL' "$issuer (one URL for the browser and for the containers)" }
        $d = $eco['ECOSYSTEM_DOMAIN']
        foreach ($h in @($sel | ForEach-Object { if ($_ -eq 'nexus') { "nexus.$d" } else { "$_.$d" } })) {
            try {
                $ips = [System.Net.Dns]::GetHostAddresses($h) | ForEach-Object { $_.ToString() }
                if ($ips -contains '127.0.0.1' -or $ips -contains '::1') { Add-Result PASS "Name $h" 'resolves to the loopback address' }
                else { Add-Result WARN "Name $h" "resolves to $($ips -join ', '), not 127.0.0.1: the browser would not reach the published port; add '127.0.0.1 $h' to the hosts file" }
            } catch { Add-Result WARN "Name $h" "does not resolve (offline?): add '127.0.0.1 $h' to the hosts file (Windows: C:\Windows\System32\drivers\etc\hosts, as administrator)" }
        }
        $bind = $eco['BIND_ADDRESS']
        $listening = @()
        $ps = Invoke-Quiet $eng.Exe @('ps', '--format', '{{.Ports}}')
        foreach ($p in $sel) {
            foreach ($u in $Catalog[$p].Urls) {
                $penv = Get-ProductEnv $p; $port = $penv[$u.Port]; if (-not $port) { $port = $eco[$u.Port] }
                if (-not $port) { continue }
                $l = $null
                try {
                    $addr = [System.Net.IPAddress]::Parse($(if ($bind -eq '0.0.0.0') { '0.0.0.0' } else { $bind }))
                    $l = New-Object System.Net.Sockets.TcpListener($addr, [int]$port); $l.Start(); $l.Stop()
                    Add-Result PASS "Port $port" "free for $($Catalog[$p].Title) $($u.Name)"
                } catch {
                    if ($ps.Text -match "[:>]$port->") { Add-Result PASS "Port $port" "already published by a running container ($($Catalog[$p].Title) $($u.Name))" }
                    else { Add-Result FAIL "Port $port" "in use by another program ($($Catalog[$p].Title) $($u.Name)); change it in .state/ecosystem.env and run init -Force-less edit of the product env" }
                }
            }
        }
    }
    $drive = try { (Get-Item $Here).PSDrive } catch { $null }
    if ($drive -and $drive.Free -and $drive.Free -lt 10GB) { Add-Result WARN 'Disk' "$([int]($drive.Free / 1GB)) GB free on $($drive.Name):, 10 GB advised for images and volumes" }
    Complete-Checks
}

function Invoke-Init {
    $sel = @(Resolve-Products)
    New-Item -ItemType Directory -Path $State -Force | Out-Null
    $hist = Save-History
    if ($hist) { Say "Previous settings kept in $hist" }
    $ecoFile = Join-Path $State 'ecosystem.env'
    $eco = Read-EnvFile $ecoFile
    # An existing ecosystem.env keeps its values (init only adds missing keys), so an installation made before the OptiEcosystem name
    # keeps its network opti-ecosystem; a new one gets optiecosystem.
    $defaults = [ordered]@{ ECOSYSTEM_DOMAIN = 'localtest.me'; ECOSYSTEM_NETWORK = 'optiecosystem'; BIND_ADDRESS = '127.0.0.1'
        NEXUS_API_PORT = '8100'; NEXUS_UI_PORT = '5180'; FLEET_API_PORT = '8000'; FLEET_UI_PORT = '5173'; ENTRY_API_PORT = '8200'; ENTRY_UI_PORT = '5190'; RADAR_PORT = '8082'
        DELI_API_PORT = '8300'; DELI_UI_PORT = '5200' }
    $added = 0
    foreach ($k in $defaults.Keys) { if (-not $eco.Contains($k)) { $eco[$k] = $defaults[$k]; $added++ } }
    Write-EnvFile $ecoFile $eco 'Shared ecosystem settings (domain, network, bind address, ports)'
    Say "ecosystem.env: $added value(s) added."
    foreach ($p in $Catalog.Keys) {
        $file = Join-Path $State "$p.env"
        $isNew = -not (Test-Path $file)
        $cur = Read-EnvFile $file
        $def = Get-Defaults $p $eco
        # Compose project name optiecosystem-<product> (containers, volumes and the group in Podman Desktop) for a new env file only:
        # an existing installation keeps its project and therefore its volumes (moving data: README, "Switching to the OptiEcosystem names").
        if ($isNew) { $def = [ordered]@{ COMPOSE_PROJECT_NAME = "optiecosystem-$p" } + $def }
        $n = 0
        foreach ($k in $def.Keys) { if (-not $cur.Contains($k)) { $cur[$k] = $def[$k]; $n++ } }
        Write-EnvFile $file $cur "$($Catalog[$p].Title) container settings"
        Say ("{0}.env: {1} value(s) added{2}" -f $p, $n, $(if ($n -gt 0) { ' (secrets generated, not shown)' } else { '' }))
    }
    Say ''
    Say "Settings are in $State. Sign-in credentials for the first OptiNexus administrator: see CGO_SUPERADMIN_EMAIL / CGO_SUPERADMIN_PASSWORD in nexus.env."
    Say 'Next: optiecosystem.ps1 preflight -Products <list>, then build, then up. Connecting each product to OptiNexus (OIDC client, service account) is the onboarding step in each product''s docs.'
}

function Invoke-Build {
    $sel = @(Resolve-Products); $eng = Get-Engine
    foreach ($p in $sel) {
        Say "== build $($Catalog[$p].Title)"
        if ($p -eq 'radar') {
            $dir = Get-RepoDir 'radar'; $web = Get-RepoDir 'radar' -Web
            if (-not $dir) { Stop-Script 'OptiRadar repository not found' }
            $penv = Get-ProductEnv 'radar'
            $bargs = @('build', '-f', (Join-Path $dir 'docker/Dockerfile.container'), '-t', $(if ($penv['RADAR_IMAGE']) { $penv['RADAR_IMAGE'] } else { 'optiradar:local' }))
            if ($web) { $bargs += @('--build-context', "web-src=$web") }
            $bargs += $dir
            & $eng.Exe @bargs
            if ($LASTEXITCODE -ne 0) { Stop-Script 'OptiRadar image build failed' }
        } else {
            Invoke-Compose $p @('build', 'bootstrap', 'ui')
        }
    }
}

function Invoke-Up {
    $sel = @(Resolve-Products); $eng = Get-Engine
    Invoke-Preflight
    Ensure-Network
    $upArgs = if ($Build) { @('up', '-d', '--build') } else { @('up', '-d', '--no-build') }
    foreach ($p in $sel) {
        Say "== up $($Catalog[$p].Title)"
        if ($p -eq 'radar' -and $Build) { Invoke-Build }
        Invoke-Compose $p $(if ($p -eq 'radar') { @('up', '-d', '--no-build') } else { $upArgs })
        foreach ($u in $Catalog[$p].Urls) {
            $url = Get-LocalUrl $p $u
            if (Wait-Url $url 300) { Add-Result PASS "$($Catalog[$p].Title) $($u.Name)" "ready at $url" }
            else { Add-Result FAIL "$($Catalog[$p].Title) $($u.Name)" "not ready within 300 s at $url (compose logs: optiecosystem.ps1 status; $($eng.Name) logs <container>)" }
        }
        if ($p -eq 'nexus' -and ($script:Results | Where-Object { $_.Level -eq 'FAIL' -and $_.Check -like 'OptiNexus*' })) { Stop-Script 'OptiNexus is not ready; the products need it for sign-in and permissions. Fix it and run up again.' }
    }
    Say ''
    Show-Urls $sel
    Complete-Checks
}

function Show-Urls($sel) {
    Say 'URLs (open in the browser):'
    foreach ($p in $sel) { $penv = Get-ProductEnv $p; $pub = $penv[$Catalog[$p].Public]; Say ("  {0,-10} {1}" -f $Catalog[$p].Title, $pub) }
    if ($sel -contains 'nexus') { $eco = Get-Eco; Say ("  {0,-10} http://nexus-console.{1}:{2}" -f 'Nexus UI', $eco['ECOSYSTEM_DOMAIN'], $eco['NEXUS_UI_PORT']) }
}

function Invoke-Status {
    $sel = @(Resolve-Products)
    foreach ($p in $Catalog.Keys) {
        $dir = Get-RepoDir $p
        if (-not $dir) { Say ("{0,-10} repository not found" -f $Catalog[$p].Title); continue }
        $running = $false
        if (Test-Path (Join-Path $State "$p.env")) { $r = Invoke-Compose $p @('ps', '-q') -Quiet; $running = ($r.Code -eq 0 -and $r.Text.Trim().Length -gt 0) }
        $line = "{0,-10} {1}" -f $Catalog[$p].Title, $(if ($running) { 'containers present' } else { 'not running' })
        if ($running) { foreach ($u in $Catalog[$p].Urls) { $c = Test-Url (Get-LocalUrl $p $u) 4; $line += "  | $($u.Name): $(if ($c -eq 200) { 'ready' } else { "HTTP $c" })" } }
        Say $line
    }
}

function Invoke-Smoke {
    $sel = @(Resolve-Products); $eng = Get-Engine
    $nexus = Get-ProductEnv 'nexus'; $issuer = $nexus['NEXUS_PUBLIC_URL']
    Say "Smoke test: $($sel -join ', ')"
    foreach ($p in $sel) {
        foreach ($u in $Catalog[$p].Urls) {
            $c = Test-Url (Get-LocalUrl $p $u) 6
            if ($c -eq 200) { Add-Result PASS "$($Catalog[$p].Title) $($u.Name)" 'HTTP 200' } else { Add-Result FAIL "$($Catalog[$p].Title) $($u.Name)" "HTTP $c" }
        }
    }
    try {
        $disc = Invoke-RestMethod -Uri "$issuer/.well-known/openid-configuration" -TimeoutSec 8 -UseBasicParsing
        if ($disc.issuer -eq $issuer) { Add-Result PASS 'OIDC discovery (browser side)' "issuer = $issuer" } else { Add-Result FAIL 'OIDC discovery (browser side)' "issuer is '$($disc.issuer)', expected '$issuer'" }
        foreach ($f in 'authorization_endpoint', 'token_endpoint', 'jwks_uri') { if (-not "$($disc.$f)".StartsWith($issuer)) { Add-Result FAIL "Discovery $f" "does not start with the issuer ($($disc.$f))" } }
        $jwks = Invoke-RestMethod -Uri $disc.jwks_uri -TimeoutSec 8 -UseBasicParsing
        if (@($jwks.keys).Count -ge 1) { Add-Result PASS 'JWKS' "$(@($jwks.keys).Count) signing key(s)" } else { Add-Result FAIL 'JWKS' 'no keys published' }
    } catch { Add-Result FAIL 'OIDC discovery (browser side)' $_.Exception.Message }
    foreach ($p in $sel | Where-Object { $_ -ne 'nexus' }) {
        $disco = "$issuer/.well-known/openid-configuration"
        if ($p -eq 'radar') { $r = Invoke-Compose 'radar' @('exec', '-T', 'radar', 'curl', '-fsS', '-o', '/dev/null', '-w', '%{http_code}', $disco) -Quiet }
        else { $r = Invoke-Compose $p @('exec', '-T', $Catalog[$p].PhpService, 'php', '-r', 'echo (string) (@file_get_contents($argv[1]) ? "200" : "0");', $disco) -Quiet }
        if ($r.Code -eq 0 -and $r.Text.Trim().EndsWith('200')) { Add-Result PASS "$($Catalog[$p].Title) reaches the issuer" 'same URL works from inside the container' }
        else { Add-Result FAIL "$($Catalog[$p].Title) reaches the issuer" "container cannot read $disco (network alias / shared network)" }
    }
    $proxyChecks = @{ fleet = '/api/v1/auth/sso/status'; entry = '/api/v1/health'; deli = '/api/v1/health' }
    foreach ($p in $proxyChecks.Keys) { if ($sel -contains $p) { $url = (Get-ProductEnv $p)[$Catalog[$p].Public] + $proxyChecks[$p]; $pub = Test-Url $url 6; if ($pub -eq 200) { Add-Result PASS "$($Catalog[$p].Title) console proxies /api" $url } else { Add-Result FAIL "$($Catalog[$p].Title) console proxies /api" "HTTP $pub at $url" } } }
    if ($sel -contains 'radar') {
        # The OptiDeli map page frames OptiRadar Web: the browser refuses unless Radar names the OptiDeli origin in frame-ancestors.
        $radarUrl = (Get-LocalUrl 'radar' $Catalog['radar'].Urls[0]) -replace '/api/health$', '/'
        $deliOrigin = (Get-ProductEnv 'deli')['DELI_PUBLIC_URL']
        try {
            $resp = Invoke-WebRequest -Uri $radarUrl -TimeoutSec 6 -UseBasicParsing -ErrorAction Stop
            $csp = "$($resp.Headers['Content-Security-Policy'])"
            if ($deliOrigin -and $csp -match [regex]::Escape($deliOrigin)) { Add-Result PASS 'OptiRadar frame-ancestors' $csp }
            elseif ($sel -contains 'deli') { Add-Result FAIL 'OptiRadar frame-ancestors' "'$csp' does not name ${deliOrigin}: set WEB_FRAME_ANCESTORS in radar.env, then up -Products radar" }
            else { Add-Result PASS 'OptiRadar frame-ancestors' "$(if ($csp) { $csp } else { 'no header (WEB_FRAME_ANCESTORS empty)' })" }
        } catch { Add-Result WARN 'OptiRadar frame-ancestors' "could not read the headers of $radarUrl" }
    }
    foreach ($p in $Catalog.Keys) {
        if ($sel -contains $p) { continue }
        if (-not (Test-Path (Join-Path $State "$p.env")) -or -not (Get-RepoDir $p)) { Add-Result PASS "$($Catalog[$p].Title) not selected" 'nothing to check'; continue }
        $r = Invoke-Compose $p @('ps', '-q') -Quiet
        if ($r.Code -eq 0 -and $r.Text.Trim().Length -gt 0) { Add-Result FAIL "$($Catalog[$p].Title) not selected" 'but containers exist (unused peers must not run)' } else { Add-Result PASS "$($Catalog[$p].Title) not selected" 'not running, as intended' }
    }
    Complete-Checks
}

function Invoke-Stop {
    $sel = @(Resolve-Products -NexusOnlyWhenNamed)
    [array]::Reverse($sel)
    foreach ($p in $sel) { Say "== stop $($Catalog[$p].Title)"; Invoke-Compose $p @('stop') }
}
function Invoke-Start {
    $sel = @(Resolve-Products -NexusOnlyWhenNamed)
    Ensure-Network
    foreach ($p in $sel) { Say "== start $($Catalog[$p].Title)"; Invoke-Compose $p @('up', '-d', '--no-build') }
    Show-Urls $sel
}
function Invoke-Down {
    $sel = @(Resolve-Products -NexusOnlyWhenNamed); $eng = Get-Engine
    [array]::Reverse($sel)
    foreach ($p in $sel) { Say "== down $($Catalog[$p].Title) (containers only; volumes and env files are kept)"; Invoke-Compose $p @('down') }
    $net = (Get-Eco)['ECOSYSTEM_NETWORK']
    [void](Invoke-Quiet $eng.Exe @('network', 'rm', $net))   # refused (and ignored) while any product is still attached
}

# --- backup / restore -------------------------------------------------------------------------------------------------------------------
function ConvertTo-ArgString([string[]]$Items) {
    return (($Items | ForEach-Object { if ($_ -match '[\s"]') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ } }) -join ' ')
}
# Binary-safe pipes to and from a compose command: the child's stdout/stdin is copied as raw bytes (Start-Process redirection and
# PowerShell pipelines re-encode text, which would corrupt a tar.gz).
function Invoke-ComposeStream([string]$Product, [string[]]$ComposeArgs, [string]$InFile, [string]$OutFile) {
    $eng = Get-Engine
    $all = Get-ComposeArgs $Product $ComposeArgs
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $eng.Compose[0]
    $psi.Arguments = ConvertTo-ArgString $all
    $psi.UseShellExecute = $false
    $psi.RedirectStandardInput = [bool]$InFile
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $proc = [System.Diagnostics.Process]::Start($psi)
    $errTask = $proc.StandardError.ReadToEndAsync()
    $outStream = $null; $outTask = $null
    if ($OutFile) {
        $outStream = [System.IO.File]::Create($OutFile)
        $outTask = $proc.StandardOutput.BaseStream.CopyToAsync($outStream)
    } else { $outTask = $proc.StandardOutput.BaseStream.CopyToAsync([System.IO.Stream]::Null) }
    if ($InFile) {
        $in = [System.IO.File]::OpenRead($InFile)
        try { $in.CopyTo($proc.StandardInput.BaseStream) } finally { $in.Dispose(); $proc.StandardInput.BaseStream.Close() }
    }
    $outTask.Wait(); $proc.WaitForExit()
    if ($outStream) { $outStream.Dispose() }
    return [pscustomobject]@{ Code = $proc.ExitCode; Err = $errTask.Result }
}
function Invoke-ComposeToFile([string]$Product, [string[]]$ComposeArgs, [string]$File) { return Invoke-ComposeStream $Product $ComposeArgs $null $File }
function Invoke-ComposeFromFile([string]$Product, [string[]]$ComposeArgs, [string]$File) { return Invoke-ComposeStream $Product $ComposeArgs $File $null }

# What is backed up per product: name, service, command inside the container
function Get-BackupItems([string]$Product) {
    $def = $Catalog[$Product]; $items = @()
    $pgService = $def.Postgres
    $items += @{ Name = 'postgres.sql'; Service = $pgService; Dump = @('sh', '-c', 'pg_dump -U $POSTGRES_USER -d $POSTGRES_DB --no-owner --clean --if-exists'); Load = @('sh', '-c', 'psql -v ON_ERROR_STOP=1 -q -U $POSTGRES_USER -d $POSTGRES_DB') }
    if ($def.Mongo) { $items += @{ Name = 'mongo.archive.gz'; Service = $def.Mongo; Dump = @('mongodump', '--archive', '--gzip', '--quiet'); Load = @('mongorestore', '--archive', '--gzip', '--drop', '--quiet') } }
    if ($Product -eq 'nexus') { $items += @{ Name = 'keys.tgz'; Service = 'api'; Dump = @('tar', 'czf', '-', '-C', '/var/keys', '.'); Load = @('tar', 'xzf', '-', '-C', '/var/keys') } }
    if ($def.Storage) { $items += @{ Name = 'storage.tgz'; Service = $def.PhpService; Dump = @('tar', 'czf', '-', '-C', $def.Storage, '.'); Load = @('tar', 'xzf', '-', '-C', $def.Storage) } }
    return $items
}

function Invoke-BackupCmd([string]$Label = 'backup') {
    $sel = @(Resolve-Products)
    $dest = Join-Path $Backups ("{0}-{1}" -f (Get-Date -Format 'yyyyMMdd-HHmmss'), $Label)
    New-Item -ItemType Directory -Path $dest -Force | Out-Null
    $manifest = [ordered]@{ created = (Get-Date).ToString('o'); products = $sel; files = @(); revisions = @{} }
    foreach ($p in $sel) {
        $g = Get-GitInfo (Get-RepoDir $p); if ($g) { $manifest.revisions[$p] = "$($g.Branch) $($g.Sha)" }
        foreach ($item in Get-BackupItems $p) {
            $file = Join-Path $dest "$p-$($item.Name)"
            $r = Invoke-ComposeToFile $p (@('exec', '-T', $item.Service) + $item.Dump) $file
            if ($r.Code -ne 0) { Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue; Add-Result FAIL "Backup $p/$($item.Name)" "exit $($r.Code): $(($r.Err -split "`n" | Select-Object -First 1))"; continue }
            $len = (Get-Item -LiteralPath $file).Length
            $hash = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash
            $manifest.files += [ordered]@{ product = $p; name = "$p-$($item.Name)"; bytes = $len; sha256 = $hash }
            Add-Result PASS "Backup $p/$($item.Name)" "$len bytes"
        }
    }
    ($manifest | ConvertTo-Json -Depth 5) | Set-Content -LiteralPath (Join-Path $dest 'manifest.json') -Encoding UTF8
    Say "Backup written to $dest (no secrets inside the manifest; the dumps contain your data, protect the folder)."
    Complete-Checks
    return $dest
}

function Invoke-RestoreCmd {
    if (-not $Backup) { Stop-Script 'restore needs -Backup <folder> (see deploy/optiecosystem/backups)' }
    $dir = if (Test-Path $Backup) { (Resolve-Path $Backup).Path } else { Join-Path $Backups $Backup }
    if (-not (Test-Path (Join-Path $dir 'manifest.json'))) { Stop-Script "No manifest.json in $dir" }
    $m = Get-Content -LiteralPath (Join-Path $dir 'manifest.json') -Raw | ConvertFrom-Json
    $sel = @(Resolve-Products)
    if (-not $ConfirmReplace) {
        Say "Restore would REPLACE the current data of: $((@($m.products) | Where-Object { $sel -contains $_ }) -join ', ') with the contents of $dir."
        Say 'A fresh backup of the current state is taken first. Repeat the command with -ConfirmReplace to go ahead.'
        return
    }
    foreach ($f in @($m.files)) { $path = Join-Path $dir $f.name; if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash -ne $f.sha256) { Stop-Script "Checksum mismatch for $($f.name): the backup is damaged, nothing was restored." } }
    $pre = Invoke-BackupCmd 'pre-restore'
    Say "Current state saved to $pre"
    foreach ($p in $sel) {
        foreach ($item in Get-BackupItems $p) {
            $file = Join-Path $dir "$p-$($item.Name)"
            if (-not (Test-Path $file)) { Add-Result WARN "Restore $p/$($item.Name)" 'not in this backup'; continue }
            $r = Invoke-ComposeFromFile $p (@('exec', '-T', $item.Service) + $item.Load) $file
            if ($r.Code -eq 0) { Add-Result PASS "Restore $p/$($item.Name)" 'applied' } else { Add-Result FAIL "Restore $p/$($item.Name)" "exit $($r.Code): $(($r.Err -split "`n" | Select-Object -First 1)). The pre-restore backup is in $pre" }
        }
    }
    Complete-Checks
}

function Invoke-Manifest {
    $sel = @($Catalog.Keys)
    $lock = [ordered]@{ created = (Get-Date).ToString('o'); repositories = [ordered]@{} }
    foreach ($p in $sel) { $dir = Get-RepoDir $p; $g = Get-GitInfo $dir; if ($g) { $lock.repositories[$p] = [ordered]@{ branch = $g.Branch; commit = $g.Sha; dirty = $g.Dirty } } }
    $web = Get-RepoDir 'radar' -Web; $gw = Get-GitInfo $web; if ($gw) { $lock.repositories['radar-web'] = [ordered]@{ branch = $gw.Branch; commit = $gw.Sha; dirty = $gw.Dirty } }
    $file = Join-Path $Here 'REVISIONS.lock.json'
    ($lock | ConvertTo-Json -Depth 5) | Set-Content -LiteralPath $file -Encoding UTF8
    Say "Wrote $file. Commit it to record the revisions this installation was built from."
}

function Invoke-RollbackConfig {
    $histDir = Join-Path $State 'history'
    if (-not (Test-Path $histDir)) { Stop-Script 'No saved settings yet (history is written by init).' }
    $all = Get-ChildItem -Path $histDir -Directory | Sort-Object Name
    if (-not $To) { Say 'Saved settings (use -To <name>):'; $all | ForEach-Object { Say "  $($_.Name)" }; return }
    $src = Join-Path $histDir $To
    if (-not (Test-Path $src)) { Stop-Script "No saved settings named $To" }
    [void](Save-History)
    Get-ChildItem -Path $src -Filter '*.env' -File | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $State -Force }
    Say "Settings restored from $To. Apply them with: optiecosystem.ps1 up (containers are re-created; no image rebuild and no data change)."
}

function Show-Help {
    Get-Help $PSCommandPath -Detailed | Out-String | Write-Host
}

if ($Command -ne 'help') { Import-LegacyState }

switch ($Command) {
    'help' { Show-Help }
    'sync' { Invoke-Sync }
    'preflight' { Invoke-Preflight }
    'init' { Invoke-Init }
    'build' { Invoke-Build }
    'up' { Invoke-Up }
    'status' { Invoke-Status }
    'smoke' { Invoke-Smoke }
    'stop' { Invoke-Stop }
    'start' { Invoke-Start }
    'down' { Invoke-Down }
    'backup' { [void](Invoke-BackupCmd) }
    'restore' { Invoke-RestoreCmd }
    'upgrade' { [void](Invoke-BackupCmd 'pre-upgrade'); Invoke-Build; $script:Build = $false; Invoke-Up }
    'manifest' { Invoke-Manifest }
    'rollback-config' { Invoke-RollbackConfig }
}
