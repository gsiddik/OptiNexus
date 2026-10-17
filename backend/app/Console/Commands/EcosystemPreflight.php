<?php

namespace App\Console\Commands;

use App\Services\Oidc\OidcKeyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deployment preflight: refuses a configuration that cannot work before containers start looping on it. It reads configuration and
 * asks the database one question; it changes nothing and never prints a secret.
 */
class EcosystemPreflight extends Command
{
    protected $signature = 'ecosystem:preflight {--skip-database : Do not connect to the database (checked before the database exists)} {--skip-keys : Do not require the signing key yet (the bootstrap creates it)}';

    protected $description = 'Check that OptiNexus is configured to run (database driver, OIDC issuer, signing key, application key)';

    public function handle(OidcKeyService $keys): int
    {
        $production = app()->environment('production');
        $results = [];

        $results[] = config('app.key') ? ['APP_KEY', 'OK', 'set'] : ['APP_KEY', 'FAIL', 'empty: run `php artisan key:generate --show` and set APP_KEY'];

        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        if ($driver !== 'pgsql') {
            $results[] = ['Database driver', 'FAIL', "'{$driver}' is not supported: the migrations are PostgreSQL-specific (set DB_CONNECTION=pgsql)"];
        } elseif ($this->option('skip-database')) {
            $results[] = ['Database driver', 'OK', 'pgsql (connection not checked)'];
        } else {
            try {
                DB::connection()->getPdo();
                $results[] = ['Database', 'OK', 'pgsql, connected'];
            } catch (Throwable $e) {
                $results[] = ['Database', 'FAIL', 'cannot connect to '.config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port').' ('.class_basename($e).')'];
            }
        }

        $results[] = $this->issuer($production);

        if (! $this->option('skip-keys')) {
            try {
                $keys->keyId();
                $results[] = ['OIDC signing key', 'OK', 'RSA key available'];
            } catch (Throwable $e) {
                $results[] = ['OIDC signing key', 'FAIL', $e->getMessage()];
            }
        }

        if ($production && in_array(config('queue.default'), ['sync', 'null'], true)) {
            $results[] = ['Queue', 'FAIL', "QUEUE_CONNECTION='".config('queue.default')."' would run deliveries inside requests; use database or redis"];
        }
        if ($production && config('app.debug')) {
            $results[] = ['APP_DEBUG', 'FAIL', 'must be false in production'];
        }

        $failed = 0;
        foreach ($results as [$check, $status, $detail]) {
            $this->components->twoColumnDetail($check, ($status === 'OK' ? '<fg=green>OK</>' : ($status === 'WARN' ? '<fg=yellow>WARN</>' : '<fg=red>FAIL</>'))." {$detail}");
            $failed += $status === 'FAIL' ? 1 : 0;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0:string,1:string,2:string} */
    private function issuer(bool $production): array
    {
        $issuer = (string) config('oidc.issuer');
        $parts = parse_url($issuer);

        if ($issuer === '' || ! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            return ['OIDC issuer', 'FAIL', 'empty or not an http(s) URL: set OIDC_ISSUER (or APP_URL) to the public base URL browsers use to reach OptiNexus'];
        }
        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return ['OIDC issuer', 'FAIL', "'{$issuer}' must be an origin only (scheme://host[:port], no path)"];
        }
        if (in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            // Fine for OptiNexus on its own; wrong as soon as another container has to reach it (OIDC_REQUIRE_PUBLIC_ISSUER=true, set by the ecosystem orchestrator).
            return config('oidc.require_public_issuer')
                ? ['OIDC issuer', 'FAIL', "'{$issuer}' points to the local machine: other containers cannot reach it; use a name they and the browser both resolve"]
                : ['OIDC issuer', 'WARN', "'{$issuer}' only works for this machine's browser; products in other containers cannot reach it"];
        }
        if ($production && $parts['scheme'] !== 'https') {
            return ['OIDC issuer', 'WARN', "'{$issuer}' is not https"];
        }

        return ['OIDC issuer', 'OK', $issuer];
    }
}
