<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Integration\Optinexus\TenantHint;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Audit A29: the sign-in start forwards `tenant_hint` (from another product's switcher) to OptiNexus, and only when it is a tenant id or
 * a tenant code. It grants nothing; OptiNexus decides which tenant is opened and whether the person may be there.
 */
class TenantHintTest extends OptinexusTestCase
{
    private function authorizeQuery(string $suffix = ''): array
    {
        $location = $this->get('/api/v1/auth/sso/redirect'.$suffix)->assertRedirectContains(self::NEXUS_URL.'/oidc/authorize')->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_a_tenant_id_is_forwarded_to_the_authorization_request(): void
    {
        $query = $this->authorizeQuery('?tenant_hint='.self::NEXUS_TENANT);

        $this->assertSame(self::NEXUS_TENANT, $query['tenant_hint']);
        $this->assertSame('S256', $query['code_challenge_method'], 'PKCE is untouched by the hint');
        $this->assertArrayHasKey('state', $query);
    }

    public function test_a_tenant_code_is_forwarded(): void
    {
        $this->assertSame('ACME-ID', $this->authorizeQuery('?tenant_hint=ACME-ID')['tenant_hint']);
        $this->assertSame('acme_id.2', $this->authorizeQuery('?tenant_hint=acme_id.2')['tenant_hint']);
    }

    public function test_no_hint_means_no_hint(): void
    {
        $this->assertArrayNotHasKey('tenant_hint', $this->authorizeQuery());
    }

    #[DataProvider('garbage')]
    public function test_anything_else_is_dropped_and_sign_in_still_starts(string $suffix): void
    {
        $this->assertArrayNotHasKey('tenant_hint', $this->authorizeQuery($suffix));
    }

    /** @return array<string,array{string}> */
    public static function garbage(): array
    {
        return [
            'markup' => ['?tenant_hint='.urlencode('<script>alert(1)</script>')],
            'a url' => ['?tenant_hint='.urlencode('https://evil.test/')],
            'a space' => ['?tenant_hint='.urlencode('acme id')],
            'a newline' => ['?tenant_hint='.urlencode("acme\nSet-Cookie: x=1")],
            'too long' => ['?tenant_hint='.str_repeat('a', 65)],
            'leading dash' => ['?tenant_hint=-acme'],
            'an array' => ['?tenant_hint[]=acme&tenant_hint[]=other'],
            'a path' => ['?tenant_hint='.urlencode('../../etc/passwd')],
            'empty' => ['?tenant_hint='],
        ];
    }

    public function test_the_sanitiser(): void
    {
        $this->assertSame(self::NEXUS_TENANT, TenantHint::sanitize(strtoupper(self::NEXUS_TENANT)), 'ids are normalised to lower case');
        $this->assertSame('ACME-ID', TenantHint::sanitize(' ACME-ID '));
        $this->assertNull(TenantHint::sanitize(null));
        $this->assertNull(TenantHint::sanitize(['a']));
        $this->assertNull(TenantHint::sanitize(str_repeat('a', 65)));
        $this->assertSame(str_repeat('a', 64), TenantHint::sanitize(str_repeat('a', 64)));
    }
}
