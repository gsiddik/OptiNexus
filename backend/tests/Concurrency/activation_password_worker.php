<?php

/*
 * Worker process for ActivationConcurrencyTest: boots the application with its own database connection, waits for a shared start time,
 * then replaces the temporary password exactly as the password page does (AccountActivationService::changePassword).
 * One JSON line on stdout: {"worker": label, "outcome": "changed" | <ActivationException state> | "error"}.
 *
 * Usage: php activation_password_worker.php <label> <userId> <issuedAtUnix> <temporaryPassword> <newPassword> <startAtUnixMicrotime>
 */

use App\Services\Ecosystem\AccountActivationService;
use App\Services\Ecosystem\ActivationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $label, $userId, $issuedAt, $temporary, $newPassword, $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    app(AccountActivationService::class)->changePassword($userId, (int) $issuedAt, $temporary, $newPassword, Request::create('/oidc/activate', 'POST'));
    $outcome = 'changed';
} catch (ActivationException $e) {
    $outcome = $e->state;
} catch (Throwable $e) {
    $outcome = 'error: '.class_basename($e).': '.$e->getMessage();
}

fwrite(STDOUT, json_encode(['worker' => $label, 'outcome' => $outcome])."\n");
