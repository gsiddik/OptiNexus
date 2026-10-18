<?php

/*
 * Worker process for InvitationConcurrencyTest: boots the application with its own database connection, waits for a shared start time,
 * then accepts one invitation exactly as the invitee's browser post does (InvitationService::accept). One JSON line on stdout:
 * {"worker": label, "outcome": "accepted" | <InvitationException state> | "error"}.
 *
 * Usage: php invitation_accept_worker.php <label> <invitationId> <tokenHash> <startAtUnixMicrotime> <password>
 */

use App\Services\Delegation\InvitationException;
use App\Services\Delegation\InvitationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $label, $invitationId, $tokenHash, $startAt, $password] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    app(InvitationService::class)->accept($invitationId, $tokenHash, ['name' => 'Racer', 'password' => $password, 'current_password' => null], Request::create('/invitations/accept', 'POST'));
    $outcome = 'accepted';
} catch (InvitationException $e) {
    $outcome = $e->state;
} catch (Throwable $e) {
    $outcome = 'error: '.class_basename($e).': '.$e->getMessage();
}

fwrite(STDOUT, json_encode(['worker' => $label, 'outcome' => $outcome])."\n");
