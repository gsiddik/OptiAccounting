<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Optinexus\EntitlementProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Onboarding by invitation, OptiEntry side (contract GOVERNANCE_CONTRACTS.md §2b). OptiNexus owns the invitation: it e-mails the link and the
 * invitee sets their own password there. Entry names the signed-in Admin Tenant as the acting user, renders OptiNexus' answers, keeps no
 * copy, and no password or token passes through it (answers, audit, logs).
 */
class DelegationInvitationTest extends OptinexusTestCase
{
    private const BASE = '/api/v1/app/delegation';

    private const OTHER_TENANT = '99999999-9999-4999-8999-999999999999';

    /** @var array{token:string,tenant:Tenant,user:User,membership:TenantUser} */
    private array $session;

    /** @var list<string> everything the application logged during the test (message and context) */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $this->signedIn();
        Log::listen(function ($event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    private function api(string $method, string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->as($this->session['token'])->withHeaders($headers)->{$method.'Json'}(self::BASE.$path, $body);
    }

    /** @return list<array<string,mixed>> */
    private function calls(?string $method = null): array
    {
        return array_values(array_filter($this->nexus['requests'], fn ($r) => str_contains($r['path'], '/delegation/') && ($method === null || $r['method'] === $method)));
    }

    private function invitationCalls(?string $method = null): array
    {
        return array_values(array_filter($this->calls($method), fn ($r) => str_contains($r['path'], '/invitations')));
    }

    private function person(array $over = []): array
    {
        return $over + ['name' => 'Budi Santoso', 'email' => 'budi@example.test', 'role_ids' => [], 'locale' => 'en'];
    }

    private function accountant(): string
    {
        return $this->nexusRole('tpl-accountant')['id'];
    }

    /** @param list<string> $permissions A token for a new person who signed in with exactly these permissions. */
    private function tokenFor(array $permissions, bool $admin = false, string $sub = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'): string
    {
        $email = substr($sub, 0, 8).'@example.test';
        $this->nexusGrant($sub, self::NEXUS_TENANT, $permissions);
        $this->nexus['delegation']['users'][$sub] = ['id' => $sub, 'name' => 'Clerk', 'email' => $email, 'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => $admin, 'access' => 'ACTIVE', 'role_ids' => []];
        $this->nexus['delegation']['admins'][$sub] = $admin;

        $location = (string) $this->nexusCallback(['sub' => $sub, 'email' => $email, 'name' => 'Clerk'])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $query['ticket']])->assertOk()->json('token');
    }

    // ------------------------------------------------------------------ create

    public function test_an_invitation_is_created_in_optinexus_for_the_signed_in_admin_and_nothing_is_written_locally(): void
    {
        $users = DB::table('users')->count();
        $members = DB::table('tenant_users')->count();

        $response = $this->api('post', '/invitations', $this->person(['role_ids' => [$this->accountant()], 'locale' => 'id']), ['X-Request-Id' => 'ref-invite-1'])
            ->assertCreated()
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('email', 'budi@example.test')
            ->assertJsonPath('locale', 'id')
            ->assertJsonPath('roles.0.code', 'ACCOUNTANT')
            ->assertJsonPath('invited_by.id', self::NEXUS_USER)
            ->assertJsonPath('delivery.status', 'QUEUED')
            ->assertJsonPath('can_resend', true)
            ->assertJsonPath('can_revoke', true);

        // What OptiNexus received: exactly what a person typed plus the acting user; nothing else, in particular no password and no token.
        [$post] = $this->invitationCalls('POST');
        $this->assertStringContainsString('/delegation/tenants/'.self::NEXUS_TENANT.'/invitations', $post['url']);
        $this->assertEqualsCanonicalizing(['email', 'name', 'role_ids', 'locale', 'acting_user_id'], array_keys($post['body']));
        $this->assertSame([self::NEXUS_USER, 'id', [$this->accountant()]], [$post['body']['acting_user_id'], $post['body']['locale'], $post['body']['role_ids']]);
        $this->assertSame('ref-invite-1', $post['correlation']);

        // Entry keeps no copy of the identity or the invitation: no user, no membership, no seat.
        $this->assertSame([$users, $members], [DB::table('users')->count(), DB::table('tenant_users')->count()]);
        $this->api('get', '/users')->assertJsonPath('meta.seats.used', 1);

        // The change is audited with the actor, and the e-mail (with the token) was sent by OptiNexus only.
        $audit = DB::table('audit_logs')->where('action', 'delegation.invitation_created')->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->session['user']->id, $audit->actor_user_id);
        $this->assertSame($response->json('id'), $audit->resource_id);
        $this->assertCount(1, $this->nexus['delegation']['mail']);
    }

    public function test_the_language_of_the_invitation_is_the_one_chosen_else_the_browsers_else_english(): void
    {
        $this->api('post', '/invitations', $this->person(['email' => 'a@example.test', 'locale' => 'id']))->assertCreated()->assertJsonPath('locale', 'id');
        $this->api('post', '/invitations', ['name' => 'B', 'email' => 'b@example.test'], ['Accept-Language' => 'id-ID,id;q=0.9,en;q=0.5'])->assertCreated()->assertJsonPath('locale', 'id');
        $this->api('post', '/invitations', ['name' => 'C', 'email' => 'c@example.test'], ['Accept-Language' => 'fr-FR'])->assertCreated()->assertJsonPath('locale', 'en');
        $this->api('post', '/invitations', ['name' => 'D', 'email' => 'd@example.test'])->assertCreated()->assertJsonPath('locale', 'en');
        $this->assertSame(['id', 'id', 'en', 'en'], array_column(array_column($this->invitationCalls('POST'), 'body'), 'locale'));

        $before = count($this->invitationCalls());
        $this->api('post', '/invitations', $this->person(['email' => 'e@example.test', 'locale' => 'fr']))->assertStatus(422)->assertJsonValidationErrors(['locale']);
        $this->assertCount($before, $this->invitationCalls());
    }

    public function test_the_tenant_the_acting_user_and_any_secret_come_from_the_server_never_from_the_request(): void
    {
        $other = self::OTHER_TENANT;
        $this->api('post', '/invitations?tenant_id='.$other, $this->person([
            'tenant_id' => $other, 'acting_user_id' => $other, 'application_code' => 'fleet', 'invited_by' => $other,
            'initial_password' => 'Str0ng!Passw0rd#1', 'password' => 'Str0ng!Passw0rd#1', 'token' => 'forged-token',
        ]))->assertCreated();

        foreach ($this->invitationCalls() as $call) {
            $this->assertStringContainsString('/delegation/tenants/'.self::NEXUS_TENANT.'/', $call['url']);
            $this->assertStringNotContainsString($other, $call['url']);
            $this->assertSame(self::NEXUS_USER, $call['body']['acting_user_id']);
            foreach (['tenant_id', 'application_code', 'invited_by', 'initial_password', 'password', 'token'] as $key) {
                $this->assertArrayNotHasKey($key, $call['body']);
            }
        }

        // The same for every other call: the id of the signed-in person, even when the request names somebody else.
        $id = $this->nexusSeedInvitation(['last_sent_at' => now()->subDay()->toIso8601String()]);
        $this->api('get', '/invitations?acting_user_id='.$other.'&tenant_id='.$other)->assertOk();
        $this->api('post', "/invitations/{$id}/resend", ['acting_user_id' => $other, 'tenant_id' => $other])->assertOk();
        $this->api('post', "/invitations/{$id}/revoke", ['acting_user_id' => $other, 'tenant_id' => $other])->assertOk();
        foreach (array_slice($this->invitationCalls(), 1) as $call) {
            $this->assertStringContainsString('/delegation/tenants/'.self::NEXUS_TENANT.'/', $call['url']);
            $this->assertStringNotContainsString($other, $call['url']);
            $this->assertSame(self::NEXUS_USER, $call['body']['acting_user_id'] ?? self::NEXUS_USER);
            $this->assertArrayNotHasKey('tenant_id', $call['body']);
        }
        $this->assertStringContainsString('acting_user_id='.self::NEXUS_USER, $this->invitationCalls('GET')[0]['url']);
        $this->assertStringNotContainsString($other, $this->invitationCalls('GET')[0]['url']);
    }

    public function test_the_input_is_validated_here_before_optinexus_is_asked(): void
    {
        $this->api('post', '/invitations', $this->person(['email' => 'not-an-email']))->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->api('post', '/invitations', $this->person(['name' => '']))->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->api('post', '/invitations', $this->person(['role_ids' => ['not-a-uuid']]))->assertStatus(422)->assertJsonValidationErrors(['role_ids.0']);
        $this->api('post', '/invitations', $this->person(['email' => 'robot@optientry.invalid']))->assertStatus(422)->assertJsonPath('code', 'EMAIL_RESERVED');

        $this->assertSame([], $this->invitationCalls());
    }

    public function test_validation_errors_of_optinexus_come_back_as_field_errors(): void
    {
        $this->nexus['delegation']['force'] = ['status' => 422, 'code' => 'VALIDATION_ERROR', 'details' => ['email' => ['The email has already been taken.']]];

        $this->api('post', '/invitations', $this->person())->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_an_invitation_reserves_a_seat_in_optinexus_and_the_last_one_cannot_be_taken_twice(): void
    {
        $this->nexus['delegation']['seat']['limit'] = 2;

        $first = $this->api('post', '/invitations', $this->person())->assertCreated()->json('id');
        $this->api('get', '/invitations')->assertOk()->assertJsonPath('meta.seats.used', 1)->assertJsonPath('meta.seats.reserved', 1)->assertJsonPath('meta.seats.available', 0);

        // OptiNexus counted the reservation, OptiEntry only shows it: the next invitation is refused with OptiNexus' figures.
        $this->api('post', '/invitations', $this->person(['email' => 'second@example.test']))->assertStatus(409)
            ->assertJsonPath('code', 'SEAT_LIMIT_REACHED')->assertJsonPath('details.used', 1)->assertJsonPath('details.reserved', 1)->assertJsonPath('details.available', 0);

        // Resending adds no reservation; revoking releases it.
        $this->api('get', '/invitations')->assertJsonPath('meta.seats.reserved', 1);
        $this->api('post', "/invitations/{$first}/revoke")->assertOk();
        $this->api('get', '/invitations')->assertJsonPath('meta.seats.reserved', 0)->assertJsonPath('meta.seats.available', 1);
        $this->api('post', '/invitations', $this->person(['email' => 'second@example.test']))->assertCreated();
    }

    public function test_a_person_with_a_seat_needs_none_and_a_full_tenant_still_lets_optinexus_say_already_member(): void
    {
        $this->nexus['delegation']['seat']['limit'] = 1;

        // The signed-in Admin already holds the seat; OptiNexus then decides (ALREADY_MEMBER).
        $this->api('post', '/invitations', $this->person(['email' => 'siti@example.test']))->assertStatus(409)->assertJsonPath('code', 'ALREADY_MEMBER');
    }

    public function test_reopening_an_expired_invitation_needs_a_free_seat_again(): void
    {
        $expired = $this->nexusSeedInvitation(['email' => 'late@example.test', 'expires_at' => now()->subHour(), 'send_count' => 1, 'last_sent_at' => now()->subDay()->toIso8601String()]);
        $this->nexus['delegation']['seat']['limit'] = 1; // the Admin alone fills it; an expired invitation holds nothing

        $this->api('post', "/invitations/{$expired}/resend")->assertStatus(409)->assertJsonPath('code', 'SEAT_LIMIT_REACHED');

        $this->nexus['delegation']['seat']['limit'] = 2;
        $this->api('post', "/invitations/{$expired}/resend")->assertOk();
    }

    // ------------------------------------------------------------------ list

    public function test_the_list_is_the_answer_of_optinexus_with_status_delivery_expiry_and_inviter(): void
    {
        $sent = $this->nexusSeedInvitation(['name' => 'Sent Person', 'email' => 'sent@example.test', 'delivery_status' => 'SENT', 'role_ids' => [$this->accountant()]]);
        $failed = $this->nexusSeedInvitation(['name' => 'Failed Person', 'email' => 'failed@example.test', 'delivery_status' => 'FAILED', 'delivery_error_code' => 'MAIL_REJECTED']);
        $expired = $this->nexusSeedInvitation(['name' => 'Expired Person', 'email' => 'expired@example.test', 'expires_at' => now()->subHour()]);
        $accepted = $this->nexusSeedInvitation(['name' => 'Accepted Person', 'email' => 'accepted@example.test', 'status' => 'ACCEPTED', 'accepted_at' => now()->subHour()->toIso8601String()]);
        $revoked = $this->nexusSeedInvitation(['name' => 'Revoked Person', 'email' => 'revoked@example.test', 'status' => 'REVOKED', 'revoked_at' => now()->subHour()->toIso8601String()]);
        $foreign = $this->nexusSeedInvitation(['name' => 'Foreign Person', 'email' => 'foreign@example.test', 'tenant' => self::OTHER_TENANT]);

        $response = $this->api('get', '/invitations')->assertOk()->assertJsonPath('meta.pagination.total', 5);
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertFalse($rows->has($foreign), 'an invitation of another tenant is never listed');
        $this->assertSame('PENDING', $rows[$sent]['status']);
        $this->assertSame('ACCOUNTANT', $rows[$sent]['roles'][0]['code']);
        $this->assertSame(['id' => self::NEXUS_USER, 'name' => 'Siti Aminah'], $rows[$sent]['invited_by']);
        $this->assertSame('SENT', $rows[$sent]['delivery']['status']);
        $this->assertSame(['FAILED', 'MAIL_REJECTED'], [$rows[$failed]['delivery']['status'], $rows[$failed]['delivery']['error_code']]);
        $this->assertSame('EXPIRED', $rows[$expired]['status']);
        $this->assertTrue($rows[$expired]['can_resend']);
        $this->assertSame('ACCEPTED', $rows[$accepted]['status']);
        $this->assertNotNull($rows[$accepted]['accepted_at']);
        $this->assertSame(['REVOKED', false, false], [$rows[$revoked]['status'], $rows[$revoked]['can_resend'], $rows[$revoked]['can_revoke']]);
        $this->assertNotNull($rows[$sent]['expires_at']);

        $call = $this->invitationCalls('GET')[0];
        $this->assertStringContainsString('/delegation/tenants/'.self::NEXUS_TENANT.'/invitations', $call['url']);
        $this->assertStringContainsString('acting_user_id='.self::NEXUS_USER, $call['url']);
    }

    public function test_the_list_passes_status_search_and_page_size_on_and_validates_them(): void
    {
        $this->nexusSeedInvitation(['name' => 'Rina', 'email' => 'rina@example.test']);
        $this->nexusSeedInvitation(['name' => 'Dewi', 'email' => 'dewi@example.test', 'status' => 'REVOKED']);
        $this->nexusSeedInvitation(['name' => 'Agus', 'email' => 'agus@example.test', 'expires_at' => now()->subDay()]);

        $this->api('get', '/invitations?status=REVOKED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'dewi@example.test');
        $this->api('get', '/invitations?status=EXPIRED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'agus@example.test');
        $this->api('get', '/invitations?search=rina&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.per_page', 1);
        $this->api('get', '/invitations?per_page=2')->assertOk()->assertJsonPath('meta.pagination.last_page', 2);

        $query = [];
        parse_str((string) parse_url($this->invitationCalls('GET')[2]['url'], PHP_URL_QUERY), $query);
        $this->assertEquals(['search' => 'rina', 'page' => '1', 'per_page' => '1', 'acting_user_id' => self::NEXUS_USER], $query);

        $before = count($this->invitationCalls());
        $this->api('get', '/invitations?status=DELETED')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->api('get', '/invitations?per_page=1000')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->assertCount($before, $this->invitationCalls());
    }

    public function test_nothing_but_the_documented_fields_is_passed_on_so_a_token_never_reaches_the_browser(): void
    {
        $this->nexus['delegation']['invitation_leak'] = true; // a misbehaving OptiNexus that adds the token and its hash to its views
        $created = $this->api('post', '/invitations', $this->person())->assertCreated();
        $listed = $this->api('get', '/invitations')->assertOk();

        $token = $this->nexus['delegation']['mail'][0]['link'];
        $token = substr($token, strrpos($token, '/') + 1);
        foreach ([$created, $listed] as $response) {
            $this->assertStringNotContainsString($token, (string) $response->getContent());
            $this->assertStringNotContainsString(hash('sha256', $token), (string) $response->getContent());
        }
        $created->assertJsonMissingPath('token')->assertJsonMissingPath('token_hash');
        $listed->assertJsonMissingPath('data.0.token')->assertJsonMissingPath('data.0.token_hash');
    }

    // ------------------------------------------------------------------ resend and revoke

    public function test_resend_gives_a_new_link_and_expiry_and_the_old_link_is_not_exposed(): void
    {
        $id = $this->api('post', '/invitations', $this->person())->json('id');
        $this->nexus['delegation']['invitations'][$id]['last_sent_at'] = now()->subHour()->toIso8601String(); // the cooldown has passed
        $first = $this->nexus['delegation']['invitations'][$id]['token'];

        $this->api('post', "/invitations/{$id}/resend")->assertOk()->assertJsonPath('status', 'PENDING')->assertJsonPath('send_count', 2)->assertJsonPath('delivery.status', 'QUEUED');

        $second = $this->nexus['delegation']['invitations'][$id]['token'];
        $this->assertNotSame($first, $second);
        $this->assertCount(2, $this->nexus['delegation']['mail']);

        [, $post] = $this->invitationCalls('POST');
        $this->assertStringEndsWith("/invitations/{$id}/resend", $post['path']);
        $this->assertSame(['acting_user_id' => self::NEXUS_USER], $post['body']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'delegation.invitation_resent')->where('resource_id', $id)->count());
    }

    public function test_an_expired_invitation_can_be_resent_and_becomes_pending_again(): void
    {
        $id = $this->nexusSeedInvitation(['expires_at' => now()->subDay(), 'last_sent_at' => now()->subDays(4)->toIso8601String()]);

        $this->api('get', '/invitations')->assertJsonPath('data.0.status', 'EXPIRED')->assertJsonPath('data.0.can_resend', true);
        $this->api('post', "/invitations/{$id}/resend")->assertOk()->assertJsonPath('status', 'PENDING');
    }

    public function test_the_resend_cooldown_is_reported_with_retry_after_and_is_not_repeated(): void
    {
        $id = $this->api('post', '/invitations', $this->person())->json('id');
        $this->nexus['delegation']['invitation_cooldown'] = 600;

        $response = $this->api('post', "/invitations/{$id}/resend")->assertStatus(429)->assertJsonPath('code', 'INVITATION_RESEND_COOLDOWN');
        $wait = (int) $response->json('details.retry_after');
        $this->assertGreaterThan(5, $wait);
        $this->assertLessThanOrEqual(600, $wait);
        $response->assertHeader('Retry-After', (string) $wait);
        $this->assertNotEmpty($response->json('details.reference'));
        $this->assertCount(2, $this->invitationCalls('POST'), 'the create and one resend: a throttled change is not hammered');
        $this->assertCount(1, $this->nexus['delegation']['mail']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'delegation.refused')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'delegation.invitation_resent')->count());
    }

    public function test_the_resend_limit_is_reported(): void
    {
        $id = $this->nexusSeedInvitation(['send_count' => 3]);

        $this->api('get', '/invitations')->assertJsonPath('data.0.can_resend', false);
        $this->api('post', "/invitations/{$id}/resend")->assertStatus(409)->assertJsonPath('code', 'INVITATION_RESEND_LIMIT');
    }

    public function test_an_invitation_that_is_accepted_or_revoked_cannot_be_resent_and_says_which(): void
    {
        $accepted = $this->nexusSeedInvitation(['status' => 'ACCEPTED', 'accepted_at' => now()->toIso8601String()]);
        $revoked = $this->nexusSeedInvitation(['status' => 'REVOKED']);

        $this->api('post', "/invitations/{$accepted}/resend")->assertStatus(409)->assertJsonPath('code', 'INVITATION_NOT_PENDING')->assertJsonPath('details.status', 'ACCEPTED');
        $this->api('post', "/invitations/{$revoked}/resend")->assertStatus(409)->assertJsonPath('code', 'INVITATION_NOT_PENDING')->assertJsonPath('details.status', 'REVOKED');
        $this->api('post', "/invitations/{$accepted}/revoke")->assertStatus(409)->assertJsonPath('code', 'INVITATION_NOT_PENDING')->assertJsonPath('details.status', 'ACCEPTED');
        $this->assertSame([], $this->nexus['delegation']['mail']);
    }

    public function test_revoking_kills_the_link_records_the_reason_and_is_idempotent(): void
    {
        $id = $this->api('post', '/invitations', $this->person())->json('id');

        $this->api('post', "/invitations/{$id}/revoke", ['reason' => 'Wrong person'])->assertOk()->assertJsonPath('status', 'REVOKED')->assertJsonPath('can_revoke', false)->assertJsonPath('can_resend', false);
        $this->assertSame('Wrong person', $this->nexus['delegation']['invitations'][$id]['revoke_reason']);
        $this->api('post', "/invitations/{$id}/revoke")->assertOk()->assertJsonPath('status', 'REVOKED');

        [, $first, $second] = $this->invitationCalls('POST');
        $this->assertEquals(['reason' => 'Wrong person', 'acting_user_id' => self::NEXUS_USER], $first['body']);
        $this->assertSame(['acting_user_id' => self::NEXUS_USER], $second['body'], 'no reason, no key');
        $reasons = DB::table('audit_logs')->where('action', 'delegation.invitation_revoked')->where('resource_id', $id)->pluck('changes')->map(fn ($changes) => json_decode($changes, true)['after']['reason'])->all();
        $this->assertEqualsCanonicalizing(['Wrong person', null], $reasons);

        $this->api('post', "/invitations/{$id}/revoke", ['reason' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    // ------------------------------------------------------------------ the other refusals of §2b

    public function test_an_open_invitation_a_current_member_and_a_role_that_is_not_assignable_are_refused_as_optinexus_decides(): void
    {
        $id = $this->api('post', '/invitations', $this->person())->assertCreated()->json('id');

        $this->api('post', '/invitations', $this->person(['email' => 'BUDI@example.test']))->assertStatus(409)->assertJsonPath('code', 'INVITATION_PENDING')->assertJsonPath('details.invitation_id', $id);
        $this->api('post', '/invitations', $this->person(['email' => 'siti@example.test']))->assertStatus(409)->assertJsonPath('code', 'ALREADY_MEMBER');
        $owner = $this->nexusRole('tpl-owner')['id']; // carries a permission that is not delegable
        $this->api('post', '/invitations', $this->person(['email' => 'x@example.test', 'role_ids' => [$owner]]))->assertStatus(422)->assertJsonPath('code', 'ROLE_NOT_ASSIGNABLE')->assertJsonPath('details.role_ids', [$owner]);

        $this->assertCount(1, $this->nexus['delegation']['invitations']);
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'delegation.refused')->count(), 'every refusal OptiNexus decided is audited');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'delegation.invitation_created')->count());
    }

    public function test_an_invitation_of_another_tenant_or_application_cannot_be_used_even_with_its_id(): void
    {
        $foreign = $this->nexusSeedInvitation(['tenant' => self::OTHER_TENANT, 'email' => 'foreign@example.test']);
        $otherApp = $this->nexusSeedInvitation(['application' => 'fleet', 'email' => 'fleet@example.test']);

        foreach ([$foreign, $otherApp] as $id) {
            $this->api('post', "/invitations/{$id}/resend")->assertStatus(404)->assertJsonPath('code', 'INVITATION_NOT_FOUND');
            $this->api('post', "/invitations/{$id}/revoke", ['reason' => 'x'])->assertStatus(404)->assertJsonPath('code', 'INVITATION_NOT_FOUND');
            $this->assertSame('PENDING', $this->nexus['delegation']['invitations'][$id]['status']);
            $this->assertSame(1, $this->nexus['delegation']['invitations'][$id]['send_count']);
        }
        // A tenant in the body or query does not move the call to another tenant.
        $this->api('post', "/invitations/{$foreign}/revoke?tenant_id=".self::OTHER_TENANT, ['tenant_id' => self::OTHER_TENANT])->assertStatus(404);
        foreach ($this->invitationCalls() as $call) {
            $this->assertStringContainsString('/delegation/tenants/'.self::NEXUS_TENANT.'/', $call['url']);
        }

        // An id that is not an id never leaves this application.
        $before = count($this->invitationCalls());
        $this->api('post', '/invitations/not-an-id/resend')->assertStatus(404);
        $this->assertCount($before, $this->invitationCalls());
        $this->assertSame([], $this->nexus['delegation']['mail']);
    }

    /** @return array<string,array{0:int,1:string,2:int,3:string,4:?string}> upstream status, upstream code, status here, code here, Retry-After */
    public static function newCodes(): array
    {
        return [
            'pending' => [409, 'INVITATION_PENDING', 409, 'INVITATION_PENDING', null],
            'member' => [409, 'ALREADY_MEMBER', 409, 'ALREADY_MEMBER', null],
            'role' => [422, 'ROLE_NOT_ASSIGNABLE', 422, 'ROLE_NOT_ASSIGNABLE', null],
            'not found' => [404, 'INVITATION_NOT_FOUND', 404, 'INVITATION_NOT_FOUND', null],
            'not pending' => [409, 'INVITATION_NOT_PENDING', 409, 'INVITATION_NOT_PENDING', null],
            'cooldown' => [429, 'INVITATION_RESEND_COOLDOWN', 429, 'INVITATION_RESEND_COOLDOWN', '45'],
            'limit' => [409, 'INVITATION_RESEND_LIMIT', 409, 'INVITATION_RESEND_LIMIT', null],
            'rate limited' => [429, 'INVITATION_RATE_LIMITED', 429, 'INVITATION_RATE_LIMITED', '120'],
        ];
    }

    #[DataProvider('newCodes')]
    public function test_every_new_error_code_of_the_contract_is_translated(int $upstreamStatus, string $upstreamCode, int $status, string $code, ?string $retryAfter): void
    {
        $this->nexus['delegation']['force'] = ['status' => $upstreamStatus, 'code' => $upstreamCode, 'details' => ['invitation_id' => 'inv-1', 'status' => 'ACCEPTED', 'token' => 'must-not-pass', 'role_ids' => []], 'headers' => $retryAfter ? ['Retry-After' => $retryAfter] : []];

        $response = $this->api('post', '/invitations', $this->person())->assertStatus($status)->assertJsonPath('code', $code);

        $this->assertNotEmpty($response->json('details.reference'), 'every refusal carries the reference a person can quote');
        $this->assertStringNotContainsString('must-not-pass', (string) $response->getContent(), 'only the documented details are passed on');
        if ($retryAfter) {
            $response->assertHeader('Retry-After', $retryAfter)->assertJsonPath('details.retry_after', (int) $retryAfter);
            $this->assertCount(1, $this->invitationCalls('POST'), 'a 429 above the wait cap is returned, not slept through');
        } else {
            $response->assertHeaderMissing('Retry-After');
        }
        if ($code === 'INVITATION_PENDING') {
            $response->assertJsonPath('details.invitation_id', 'inv-1');
        }
        if ($code === 'INVITATION_NOT_PENDING') {
            $response->assertJsonPath('details.status', 'ACCEPTED');
        }
    }

    public function test_a_status_that_is_not_one_of_the_four_is_not_passed_on(): void
    {
        $this->nexus['delegation']['force'] = ['status' => 409, 'code' => 'INVITATION_NOT_PENDING', 'details' => ['status' => '<script>alert(1)</script>']];

        $this->api('post', '/invitations', $this->person())->assertStatus(409)->assertJsonMissingPath('details.status');
    }

    // ------------------------------------------------------------------ outage, retry safety, authorization

    public function test_a_change_whose_answer_is_lost_is_never_repeated_and_says_its_outcome_is_unknown(): void
    {
        $id = $this->nexusSeedInvitation();
        $this->api('get', '/invitations')->assertOk(); // the service token is cached, so the attempts below fail on the invitation call itself
        $this->nexus['down'] = true;

        $this->api('post', '/invitations', $this->person())->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE')->assertJsonPath('details.outcome_unknown', true);
        $this->api('post', "/invitations/{$id}/resend")->assertStatus(503)->assertJsonPath('details.outcome_unknown', true);
        $this->api('post', "/invitations/{$id}/revoke")->assertStatus(503)->assertJsonPath('details.outcome_unknown', true);
        $this->assertCount(3, $this->invitationCalls('POST'), 'one attempt each: a lost connection is not retried for a change');

        $this->nexus['down'] = false;
        $this->nexus['requests'] = [];
        $this->nexus['delegation']['force'] = ['status' => 502, 'code' => 'BAD_GATEWAY'];
        $this->api('post', '/invitations', $this->person())->assertStatus(503)->assertJsonPath('details.outcome_unknown', true);
        $this->assertCount(1, $this->invitationCalls('POST'), 'a 502 on a change is not retried either');

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'delegation.invitation_%')->count(), 'nothing is recorded as done that did not complete');
        $this->assertSame([], $this->nexus['delegation']['mail']);
    }

    public function test_a_list_is_repeated_once_after_a_transient_failure_and_an_outage_is_reported(): void
    {
        $this->nexus['delegation']['force'] = ['status' => 502, 'code' => 'BAD_GATEWAY'];
        $this->api('get', '/invitations')->assertOk();
        $this->assertCount(2, $this->invitationCalls('GET'));

        $this->nexus['down'] = true;
        $this->api('get', '/invitations')->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE')->assertJsonMissingPath('details.outcome_unknown');
    }

    public function test_only_an_admin_tenant_of_optinexus_gets_anything_even_with_the_local_permissions(): void
    {
        $token = $this->tokenFor(['access.user.view', 'access.user.manage']); // holds the permissions, is not an Admin Tenant
        $id = $this->nexusSeedInvitation();

        $this->as($token)->getJson(self::BASE.'/invitations')->assertForbidden()->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->as($token)->postJson(self::BASE.'/invitations', $this->person())->assertForbidden()->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->as($token)->postJson(self::BASE."/invitations/{$id}/resend")->assertForbidden()->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->as($token)->postJson(self::BASE."/invitations/{$id}/revoke")->assertForbidden()->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->assertSame('PENDING', $this->nexus['delegation']['invitations'][$id]['status']);
        $this->assertSame([], $this->nexus['delegation']['mail']);
    }

    public function test_the_permissions_of_the_users_screen_decide_before_optinexus_is_asked(): void
    {
        $viewer = $this->tokenFor(['access.user.view'], admin: true);
        $id = $this->nexusSeedInvitation();

        $this->as($viewer)->getJson(self::BASE.'/invitations')->assertOk();
        $before = count($this->invitationCalls());
        $this->as($viewer)->postJson(self::BASE.'/invitations', $this->person())->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->as($viewer)->postJson(self::BASE."/invitations/{$id}/resend")->assertForbidden();
        $this->as($viewer)->postJson(self::BASE."/invitations/{$id}/revoke")->assertForbidden();
        $this->assertCount($before, $this->invitationCalls());

        $nobody = $this->tokenFor(['accounting.journal.view'], admin: true, sub: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee');
        $this->as($nobody)->getJson(self::BASE.'/invitations')->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_a_read_only_subscription_refuses_every_change_but_still_lists(): void
    {
        $id = $this->nexusSeedInvitation();
        $this->nexus['tenants'][self::NEXUS_TENANT]['subscriptions'] = [['id' => 'sub-1', 'status' => 'PAST_DUE']];
        app(EntitlementProjector::class)->sync($this->session['tenant']);

        $this->api('post', '/invitations', $this->person())->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');
        $this->api('post', "/invitations/{$id}/resend")->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');
        $this->api('post', "/invitations/{$id}/revoke")->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');
        $this->assertSame([], $this->invitationCalls('POST'));
        $this->api('get', '/invitations')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_in_standalone_mode_the_invitation_routes_refuse_and_ask_nobody(): void
    {
        config(['optientry.identity_mode' => 'standalone']);
        $tenant = $this->tenant('plain-co');
        $token = $this->tenantToken($this->member($tenant)[0], $tenant);

        $this->as($token)->getJson(self::BASE.'/invitations')->assertStatus(409)->assertJsonPath('code', 'DELEGATION_NOT_AVAILABLE');
        $this->as($token)->postJson(self::BASE.'/invitations', $this->person())->assertStatus(409)->assertJsonPath('code', 'DELEGATION_NOT_AVAILABLE');
        $this->assertSame([], $this->invitationCalls());
    }

    // ------------------------------------------------------------------ no secret anywhere

    public function test_no_token_and_no_password_appears_in_answers_audit_logs_or_what_is_sent(): void
    {
        $responses = [];
        $created = $this->api('post', '/invitations', $this->person(['initial_password' => 'Str0ng!Passw0rd#1', 'role_ids' => [$this->accountant()]]));
        $responses[] = $created;
        $id = $created->json('id');
        $this->nexus['delegation']['invitations'][$id]['last_sent_at'] = now()->subHour()->toIso8601String();
        $responses[] = $this->api('post', "/invitations/{$id}/resend");
        $responses[] = $this->api('get', '/invitations');
        $responses[] = $this->api('post', "/invitations/{$id}/revoke", ['reason' => 'Mistake']);
        $responses[] = $this->api('post', "/invitations/{$id}/resend")->assertStatus(409); // not pending any more
        $this->nexus['down'] = true;
        $responses[] = $this->api('post', '/invitations', $this->person(['email' => 'down@example.test']))->assertStatus(503);

        $tokens = array_map(fn ($mail) => substr($mail['link'], strrpos($mail['link'], '/') + 1), $this->nexus['delegation']['mail']);
        $this->assertCount(2, $tokens);
        $secrets = [...$tokens, ...array_map(fn ($t) => hash('sha256', $t), $tokens), 'Str0ng!Passw0rd#1', 'Passw0rd'];

        $haystacks = [
            'answers' => implode("\n", array_map(fn ($r) => (string) $r->getContent().json_encode($r->headers->all()), $responses)),
            'audit' => (string) json_encode(DB::table('audit_logs')->get()),
            'log' => implode("\n", $this->logged),
            'sent to OptiNexus' => (string) json_encode(array_map(fn ($r) => [$r['url'], $r['body']], $this->invitationCalls())),
        ];
        foreach ($haystacks as $where => $text) {
            if ($where !== 'log') {
                $this->assertNotSame('', $text, $where);
            }
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text, "a secret appears in: {$where}");
            }
        }
    }
}
