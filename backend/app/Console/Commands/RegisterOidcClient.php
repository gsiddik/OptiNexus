<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesSecretFile;
use App\Models\Application;
use App\Models\OidcClient;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Operator path to register (or update) the OpenID Connect client of a product (audit A08/A14), for first boot and the Podman
 * rehearsal. PKCE is always required here - there is no switch to turn it off. Idempotent by (application, name): a repeat run
 * updates the URLs and keeps the secret; the secret exists only on creation or with --rotate and goes to --secret-file (mode
 * 0600), never to stdout or logs.
 */
class RegisterOidcClient extends Command
{
    use WritesSecretFile;

    protected $signature = 'ecosystem:register-oidc-client
        {application_code : application the client belongs to}
        {--name= : client name (default: the application name)}
        {--redirect-uri=* : exact redirect URI (repeatable, at least one)}
        {--post-logout-uri=* : exact post-logout redirect URI (repeatable)}
        {--launch-url= : where the product switcher sends the user}
        {--backchannel-logout-uri= : back-channel logout endpoint of the product}
        {--public : register a public client (no secret)}
        {--rotate : issue a new secret for an existing confidential client}
        {--secret-file= : file that receives OIDC_CLIENT_ID / OIDC_CLIENT_SECRET (required when a secret is issued)}';

    protected $description = 'Register or update the OpenID Connect client of a product (PKCE always required; secret only to a 0600 file)';

    public function handle(AuditService $audit): int
    {
        $application = Application::query()->where('application_code', (string) $this->argument('application_code'))->first();

        if (! $application) {
            $this->error('Unknown application_code.');

            return self::FAILURE;
        }

        $redirects = array_values(array_unique(array_filter((array) $this->option('redirect-uri'))));
        $logoutUris = array_values(array_unique(array_filter((array) $this->option('post-logout-uri'))));
        $name = (string) ($this->option('name') ?: $application->name);

        foreach (array_merge($redirects, $logoutUris, array_filter([$this->option('launch-url'), $this->option('backchannel-logout-uri')])) as $url) {
            if (! preg_match('#^https?://[^\s]+$#', (string) $url) || strlen((string) $url) > 2048 || str_contains((string) $url, '#')) {
                $this->error('Every URL must be an absolute http(s) URL without a fragment.');

                return self::FAILURE;
            }
        }

        $client = OidcClient::query()->where('application_id', $application->id)->where('name', $name)->where('status', OidcClient::STATUS_ACTIVE)->first();

        if (! $client && $redirects === []) {
            $this->error('At least one --redirect-uri is required for a new client.');

            return self::FAILURE;
        }

        $secret = null;
        $created = false;

        if (! $client) {
            $secret = $this->option('public') ? null : Str::random(48);
            $client = OidcClient::create([
                'application_id' => $application->id,
                'client_id' => 'onx_'.Str::lower(Str::random(24)),
                'client_secret_hash' => $secret ? hash('sha256', $secret) : null,
                'name' => $name,
                'redirect_uris' => $redirects,
                'post_logout_redirect_uris' => $logoutUris,
                'launch_url' => $this->option('launch-url') ?: null,
                'backchannel_logout_uri' => $this->option('backchannel-logout-uri') ?: null,
                'require_pkce' => true,
                'status' => OidcClient::STATUS_ACTIVE,
            ]);
            $created = true;
        } else {
            $update = array_filter([
                'redirect_uris' => $redirects ?: null,
                'post_logout_redirect_uris' => $logoutUris ?: null,
                'launch_url' => $this->option('launch-url') ?: null,
                'backchannel_logout_uri' => $this->option('backchannel-logout-uri') ?: null,
            ], fn ($value) => $value !== null);
            $update['require_pkce'] = true;
            $client->update($update);

            if ($this->option('rotate')) {
                if (! $client->isConfidential()) {
                    $this->error('Public clients have no secret to rotate.');

                    return self::FAILURE;
                }
                $secret = Str::random(48);
                $client->update(['client_secret_hash' => hash('sha256', $secret)]);
            }
        }

        if ($secret !== null) {
            $file = (string) $this->option('secret-file');

            if ($file === '') {
                // Nothing was kept: undo a creation so the operator can run it again with a file, instead of losing the secret.
                if ($created) {
                    $client->delete();
                }
                $this->error('A secret is being issued: pass --secret-file so it can be stored with mode 0600.');

                return self::FAILURE;
            }

            if (! $this->writeSecretFile($file, ['OIDC_CLIENT_ID' => $client->client_id, 'OIDC_CLIENT_SECRET' => $secret])) {
                if ($created) {
                    $client->delete();
                }
                $this->error('The secret file could not be written.');

                return self::FAILURE;
            }
        }

        $audit->record($created ? 'sso.client.created' : 'sso.client.updated', null, applicationId: $application->id, resourceType: 'OidcClient',
            resourceId: $client->id, newValue: ['client_id' => $client->client_id, 'name' => $client->name, 'rotated' => ! $created && $secret !== null], source: 'cli');

        $this->line(($created ? 'created' : 'updated').' client_id='.$client->client_id.($secret !== null ? ' secret_written=yes' : ' secret_written=no'));

        return self::SUCCESS;
    }
}
