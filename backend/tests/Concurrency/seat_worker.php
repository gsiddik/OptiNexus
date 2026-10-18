<?php

/*
 * Worker process for SeatConcurrencyTest: boots the application with its own database connection, waits for a shared start time,
 * then takes ONE seat through ONE door, exactly as the real code path does. One JSON line on stdout:
 * {"worker": label, "door": door, "outcome": "ok" | <error code or state> | "error: ..."}.
 *
 * Usage: php seat_worker.php <door> <label> <startAtUnixMicrotime> <tenantId> <applicationId> <actorId> <arg1> [arg2]
 *   door invite  arg1 = e-mail                         (InvitationService::create, an Admin Tenant invites)
 *   door direct  arg1 = e-mail                         (DelegationService::addUser, the deprecated direct path)
 *   door grant   arg1 = existing user id               (SeatService::grantAccess, the door the operator API and the seeders use)
 *   door accept  arg1 = invitation id, arg2 = token hash (InvitationService::accept, the invitee's browser post)
 */

use App\Models\Application;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Delegation\DelegationException;
use App\Services\Delegation\DelegationService;
use App\Services\Delegation\InvitationException;
use App\Services\Delegation\InvitationService;
use App\Services\Seats\SeatException;
use App\Services\Seats\SeatService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $door, $label, $startAt, $tenantId, $applicationId, $actorId, $arg1] = $argv;
$arg2 = $argv[8] ?? null;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $request = Request::create('/api/race', 'POST');
    $tenant = Tenant::query()->findOrFail($tenantId);
    $application = Application::query()->findOrFail($applicationId);

    match ($door) {
        'invite' => app(InvitationService::class)->create(app(DelegationService::class)->contextForInviter($application, $tenant, $actorId), ['email' => $arg1, 'name' => 'Racer', 'role_ids' => []], $request),
        'direct' => app(DelegationService::class)->addUser(app(DelegationService::class)->contextForInviter($application, $tenant, $actorId), ['email' => $arg1, 'name' => 'Racer', 'initial_password' => 'race condition password 1'], $request),
        'grant' => app(SeatService::class)->grantAccess($tenant, $application, User::query()->findOrFail($arg1)),
        'accept' => app(InvitationService::class)->accept($arg1, (string) $arg2, ['name' => 'Racer', 'password' => 'race condition password 1', 'current_password' => null], $request),
    };
    $outcome = 'ok';
} catch (SeatException|DelegationException $e) {
    $outcome = $e->errorCode;
} catch (InvitationException $e) {
    $outcome = $e->state.($e->getMessage() !== $e->state ? ':'.$e->getMessage() : '');
} catch (Throwable $e) {
    $outcome = 'error: '.class_basename($e).': '.$e->getMessage();
}

fwrite(STDOUT, json_encode(['worker' => $label, 'door' => $door, 'outcome' => $outcome])."\n");
