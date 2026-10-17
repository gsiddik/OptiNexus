<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesSecretFile;
use App\Models\Application;
use App\Models\ServiceAccount;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

/**
 * Operator path to register the machine-to-machine service account of a product (audit A02/A23): one OAuth client-credentials
 * client bound to ONE application. The account is created centrally here (or in the portal); a product can never create one for
 * itself. Idempotent by (application, name); the secret exists only on creation or with --rotate and goes to --secret-file
 * (mode 0600), never to stdout or logs.
 */
class RegisterServiceAccount extends Command
{
    use WritesSecretFile;

    protected $signature = 'ecosystem:register-service-account
        {application_code : application the service account is bound to}
        {--name= : service account name (default: "<application> service")}
        {--tenant= : bind the account to one tenant id (optional)}
        {--rotate : issue a new secret for the existing account}
        {--secret-file= : file that receives M2M_CLIENT_ID / M2M_CLIENT_SECRET (required when a secret is issued)}';

    protected $description = 'Register the service account of a product (bound to its application; secret only to a 0600 file)';

    public function handle(ClientRepository $clients, AuditService $audit): int
    {
        $application = Application::query()->where('application_code', (string) $this->argument('application_code'))->first();

        if (! $application || $application->application_code === 'cgo') {
            $this->error('Unknown or non-registrable application_code.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $application->application_code.' service');
        $tenantId = $this->option('tenant') ?: null;
        $file = (string) $this->option('secret-file');

        $account = ServiceAccount::query()->where('application_id', $application->id)->where('name', $name)
            ->where('status', ServiceAccount::STATUS_ACTIVE)->first();

        $client = null;
        $created = false;

        if (! $account) {
            if ($file === '') {
                $this->error('A secret is being issued: pass --secret-file so it can be stored with mode 0600.');

                return self::FAILURE;
            }

            $client = $clients->createClientCredentialsGrantClient($name);
            $account = ServiceAccount::create([
                'application_id' => $application->id, 'tenant_id' => $tenantId, 'name' => $name,
                'oauth_client_id' => $client->id, 'status' => ServiceAccount::STATUS_ACTIVE,
            ]);
            $created = true;
        } elseif ($this->option('rotate')) {
            if ($file === '') {
                $this->error('A secret is being issued: pass --secret-file so it can be stored with mode 0600.');

                return self::FAILURE;
            }

            $client = $account->oauthClient;
            if (! $client) {
                $this->error('The service account has no OAuth client.');

                return self::FAILURE;
            }
            $clients->regenerateSecret($client);
        }

        if ($client !== null) {
            if (! $this->writeSecretFile($file, ['M2M_CLIENT_ID' => (string) $client->id, 'M2M_CLIENT_SECRET' => (string) $client->plainSecret])) {
                $this->error('The secret file could not be written; run again with --rotate to issue a fresh secret.');

                return self::FAILURE;
            }
        }

        $audit->record($created ? 'service_account.created' : 'service_account.secret_rotated', null, applicationId: $application->id, tenantId: $account->tenant_id,
            resourceType: 'ServiceAccount', resourceId: $account->id, newValue: ['name' => $account->name], source: 'cli');

        $this->line(($created ? 'created' : ($client ? 'rotated' : 'exists')).' service_account='.$account->id.($client ? ' secret_written=yes' : ' secret_written=no'));

        return self::SUCCESS;
    }
}
