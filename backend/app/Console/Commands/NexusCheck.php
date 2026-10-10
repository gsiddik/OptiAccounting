<?php

namespace App\Console\Commands;

use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\NexusApi;
use App\Domain\Integration\Optinexus\OidcClient;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class NexusCheck extends Command
{
    /** Former name (before the OptiEntry rename); kept so existing cron entries and runbooks keep working. */
    protected $aliases = ['optiaccounting:nexus:check'];

    protected $signature = 'optientry:nexus:check';

    protected $description = 'Verify the OptiNexus configuration against the live OptiNexus (settings, discovery, signing keys, service account)';

    public function handle(OidcClient $oidc, NexusApi $api): int
    {
        if (! OptinexusSettings::active()) {
            $this->components->warn('OPTIENTRY_IDENTITY_MODE is not "optinexus"; nothing to check.');

            return self::SUCCESS;
        }

        $ok = true;
        $step = function (string $label, callable $check) use (&$ok): void {
            try {
                $detail = $check();
                $this->components->twoColumnDetail($label, '<fg=green>OK</>'.($detail ? " ({$detail})" : ''));
            } catch (IdentityProviderUnavailable $e) {
                $ok = false;
                $this->components->twoColumnDetail($label, '<fg=red>FAIL</> '.($e->details['reason'] ?? $e->getMessage()));
            } catch (\Throwable $e) {
                $ok = false;
                $this->components->twoColumnDetail($label, '<fg=red>FAIL</> '.$e->getMessage());
            }
        };

        $step('Settings: sign-in (OPTINEXUS_BASE_URL, SSO client)', function () {
            if ($missing = OptinexusSettings::missingForSso()) {
                throw new \RuntimeException('missing '.implode(', ', $missing));
            }
            $redirect = OptinexusSettings::redirectUri();

            return "redirect URI {$redirect}";
        });
        $step('Settings: service account', function () {
            if ($missing = OptinexusSettings::missingForService()) {
                throw new \RuntimeException('missing '.implode(', ', $missing));
            }
        });
        $step('Discovery document', fn () => 'issuer '.$oidc->discovery()['issuer']);
        $step('Signing keys (JWKS)', function () use ($oidc) {
            $keys = $oidc->jwks(refresh: true);

            return count($keys['keys'] ?? []).' key(s)';
        });
        $step('Service account token and scopes', function () use ($api) {
            Cache::forget('optinexus.service.token');
            // A harmless authorization question for an unknown user: proves the token and the authorization.check scope.
            $response = $api->send('POST', '/authorization/check', [
                'user_id' => '00000000-0000-0000-0000-000000000000', 'application_code' => OptinexusSettings::applicationCode(), 'permission' => OptinexusSettings::applicationCode().'.check',
            ]);
            if ($response->status() === 404) {
                throw new \RuntimeException('application "'.OptinexusSettings::applicationCode().'" is not registered in OptiNexus');
            }
            if ($response->status() === 403) {
                throw new \RuntimeException('the service account lacks a required scope ('.NexusApi::SCOPES.')');
            }
            $api->data($response);
        });

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
