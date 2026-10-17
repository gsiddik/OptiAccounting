<?php

namespace Tests\Feature\Optinexus;

use Tests\TestCase;

/**
 * A refusal the delegation layer can answer with must read well in both languages: every code it emits has an English and an
 * Indonesian message in the console's catalog, so a screen never falls back to a raw code or to the wrong language.
 */
class DelegationMessagesTest extends TestCase
{
    public function test_every_delegation_error_code_has_a_message_in_both_languages(): void
    {
        $messages = base_path('../frontend/src/i18n/messages/errors.ts');
        if (! is_file($messages)) {
            $this->markTestSkipped('frontend sources not present');
        }
        $catalog = (string) file_get_contents($messages);

        $dir = app_path('Domain/Integration/Optinexus/Delegation');
        $errors = (string) file_get_contents($dir.'/DelegationErrors.php');
        $service = (string) file_get_contents($dir.'/TenantDelegation.php');

        $start = strpos($errors, 'PASS_THROUGH = [');
        $block = substr($errors, $start, strpos($errors, '];', $start) - $start);
        preg_match_all("/'([A-Z_]+)' =>/", $block, $passThrough);
        preg_match_all("/new DelegationFailure\([^;]*?, '([A-Z_]+)', \d{3}/s", $errors.$service, $built);
        preg_match_all("/'(DELEGATION_[A-Z_]+|TENANT_NOT_LINKED|ACTOR_NOT_LINKED)'/", $service, $reasons);

        $codes = array_values(array_unique([...$passThrough[1], ...$built[1], ...$reasons[1]]));
        $this->assertGreaterThan(15, count($codes), 'the scan must find the codes');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression("/'error\.{$code}': \{\s*id: '.+',\s*en: '.+'/s", $catalog, "error.{$code} needs an Indonesian and an English message");
        }
    }
}
