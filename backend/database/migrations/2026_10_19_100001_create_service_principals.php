<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ecosystem audit A23 (additive; nothing merged is rewritten, no posted data is touched).
 *
 * Until now "this user is a machine, not a person, so it takes no seat" was decided by an e-mail suffix (`*.optientry.invalid`). Anybody who
 * could create a user with such an address got a free seat, and nothing marked who really is a machine. The registry replaces the suffix as
 * the source of that decision:
 *
 *   service_principals     one row per machine identity of a tenant. Rows are written only by the issue command / platform API of the
 *                          platform operator (and once by this migration for the existing per-tenant OptiFleet users). Immutable: the only
 *                          change a row ever sees is its revocation, which is final. There is no flag a tenant can set.
 *   triggers               the principal's user can never get a password, a different address, an OptiNexus subject, a role, a platform role
 *                          or a membership of another tenant; a registry row is never deleted; the reserved address range
 *                          (`optientry.invalid`) belongs to registered principals only (checked at commit, so the user and its principal
 *                          row are created in one transaction).
 *
 * Existing users on the reserved range that do NOT qualify for the mapping (a password, roles, several tenants, ...) are left exactly as
 * they are: they are people, they take a seat, and the skipped list is written to the audit log for the operator.
 */
return new class extends Migration
{
    public const RESERVED_DOMAIN = 'optientry.invalid';

    /** kind => [source system, local part of the first address] (the same table lives in ServicePrincipalKinds; a migration stays self-contained) */
    private const KINDS = ['OPTIFLEET_INTEGRATION' => ['optifleet', 'optifleet.integration']];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION optientry_reserved_address(addr text) RETURNS boolean AS $$
                SELECT rtrim(lower(regexp_replace(addr, '^.*@', '')), '.') = 'optientry.invalid'
                    OR rtrim(lower(regexp_replace(addr, '^.*@', '')), '.') LIKE '%.optientry.invalid'
            $$ LANGUAGE sql IMMUTABLE;
        SQL);

        Schema::create('service_principals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('kind', 40);
            $table->string('source_system', 40);
            $table->string('issued_via', 12);
            $table->uuid('issued_by')->nullable();
            $table->string('issued_by_label', 120)->nullable();
            $table->string('reason', 500);
            $table->timestampTz('issued_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->string('revoked_by_label', 120)->nullable();
            $table->string('revoke_reason', 500)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique('user_id'); // a user is at most one principal, and never again a person
            $table->index(['tenant_id', 'kind']);
        });
        DB::statement("ALTER TABLE service_principals ADD CONSTRAINT service_principals_kind_check CHECK (kind ~ '^[A-Z][A-Z0-9_]{2,39}$')");
        DB::statement("ALTER TABLE service_principals ADD CONSTRAINT service_principals_via_check CHECK (issued_via IN ('CLI','PLATFORM_API','BACKFILL','SEEDER'))");
        DB::statement('ALTER TABLE service_principals ADD CONSTRAINT service_principals_reason_check CHECK (length(btrim(reason)) > 0)');
        DB::statement('ALTER TABLE service_principals ADD CONSTRAINT service_principals_revoked_check CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL))');
        // one live identity per tenant and kind; a revoked one makes room for a new one (with a new user)
        DB::statement('CREATE UNIQUE INDEX service_principals_one_live ON service_principals (tenant_id, kind) WHERE revoked_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION service_principals_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'service_principals rows are never removed; revoke the principal instead' USING ERRCODE = '23514';
                END IF;
                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'service_principals: a revoked principal is final' USING ERRCODE = '23514';
                END IF;
                IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.kind <> OLD.kind
                   OR NEW.source_system <> OLD.source_system OR NEW.issued_via <> OLD.issued_via OR NEW.issued_by IS DISTINCT FROM OLD.issued_by
                   OR NEW.issued_by_label IS DISTINCT FROM OLD.issued_by_label OR NEW.reason <> OLD.reason OR NEW.issued_at <> OLD.issued_at THEN
                    RAISE EXCEPTION 'service_principals: a principal cannot be edited, only revoked' USING ERRCODE = '23514';
                END IF;
                IF NEW.revoked_at IS NULL THEN
                    RAISE EXCEPTION 'service_principals: the only change allowed is revocation' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER service_principals_guard BEFORE UPDATE OR DELETE ON service_principals FOR EACH ROW EXECUTE FUNCTION service_principals_guard()');
        // No TRUNCATE trigger on purpose: like every other immutable table here, emptying a table is a privilege question (the runtime role has no
        // TRUNCATE right in production) and the test harness has to be able to reset the database (`truncate tenants cascade`).

        // A principal is created for a user that is a machine from the start: no password, on the reserved range, no OptiNexus identity, no
        // role anywhere, and members of no other tenant.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION service_principals_validate() RETURNS trigger AS $$
            DECLARE person users%ROWTYPE;
            BEGIN
                SELECT * INTO person FROM users WHERE id = NEW.user_id;
                IF person.id IS NULL THEN
                    RAISE EXCEPTION 'service_principals: unknown user' USING ERRCODE = '23514';
                END IF;
                IF person.password IS NOT NULL OR person.optinexus_subject IS NOT NULL THEN
                    RAISE EXCEPTION 'service_principals: a person (password or OptiNexus identity) cannot become a service principal' USING ERRCODE = '23514';
                END IF;
                IF NOT optientry_reserved_address(person.email) THEN
                    RAISE EXCEPTION 'service_principals: the user is not on the reserved address range' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM tenant_users WHERE user_id = NEW.user_id AND tenant_id <> NEW.tenant_id) THEN
                    RAISE EXCEPTION 'service_principals: the user belongs to another tenant' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM tenant_user_roles r JOIN tenant_users tu ON tu.id = r.tenant_user_id WHERE tu.user_id = NEW.user_id)
                   OR EXISTS (SELECT 1 FROM platform_role_assignments WHERE user_id = NEW.user_id) THEN
                    RAISE EXCEPTION 'service_principals: the user holds roles' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER service_principals_validate BEFORE INSERT ON service_principals FOR EACH ROW EXECUTE FUNCTION service_principals_validate()');

        // ... and stays one: it can not be promoted afterwards.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION service_principals_user_lock() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM service_principals WHERE user_id = OLD.id) THEN
                    IF NEW.password IS NOT NULL OR NEW.optinexus_subject IS NOT NULL THEN
                        RAISE EXCEPTION 'users: a service principal can never sign in (no password, no OptiNexus identity)' USING ERRCODE = '23514';
                    END IF;
                    IF lower(NEW.email) <> lower(OLD.email) THEN
                        RAISE EXCEPTION 'users: the address of a service principal never changes' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER users_service_principal_lock BEFORE UPDATE OF password, email, optinexus_subject ON users FOR EACH ROW EXECUTE FUNCTION service_principals_user_lock()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION service_principals_no_tenant_role() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM tenant_users tu JOIN service_principals sp ON sp.user_id = tu.user_id WHERE tu.id = NEW.tenant_user_id) THEN
                    RAISE EXCEPTION 'tenant_user_roles: a service principal holds no role' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER tenant_user_roles_no_service_principal BEFORE INSERT OR UPDATE ON tenant_user_roles FOR EACH ROW EXECUTE FUNCTION service_principals_no_tenant_role()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION service_principals_no_platform_role() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM service_principals WHERE user_id = NEW.user_id) THEN
                    RAISE EXCEPTION 'platform_role_assignments: a service principal holds no role' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER platform_role_assignments_no_service_principal BEFORE INSERT OR UPDATE ON platform_role_assignments FOR EACH ROW EXECUTE FUNCTION service_principals_no_platform_role()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION service_principals_one_tenant() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM service_principals WHERE user_id = NEW.user_id AND tenant_id <> NEW.tenant_id) THEN
                    RAISE EXCEPTION 'tenant_users: a service principal belongs to one tenant only' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER tenant_users_service_principal_one_tenant BEFORE INSERT OR UPDATE OF tenant_id, user_id ON tenant_users FOR EACH ROW EXECUTE FUNCTION service_principals_one_tenant()');

        $this->backfill();

        // After the mapping: from now on only a registered principal may hold an address of the reserved range. Checked at commit, so that the
        // user and its registry row can be written in either order inside one transaction. Rows that exist already are not re-checked.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION users_reserved_address_check() RETURNS trigger AS $$
            BEGIN
                IF optientry_reserved_address(NEW.email) AND NOT EXISTS (SELECT 1 FROM service_principals WHERE user_id = NEW.id) THEN
                    RAISE EXCEPTION 'users: the address % is reserved for service principals of the platform operator', NEW.email USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE CONSTRAINT TRIGGER users_reserved_address_check AFTER INSERT OR UPDATE OF email ON users DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION users_reserved_address_check()');
    }

    /**
     * Maps the per-tenant OptiFleet integration user that earlier releases created lazily (`optifleet.integration@<tenant code>.optientry.invalid`)
     * onto the registry. Idempotent; a user is mapped only when it is demonstrably a machine of exactly that tenant. Everything else on the
     * reserved range is left alone and listed in the audit log.
     *
     * @return array{mapped:int,skipped:int}
     */
    public function backfill(): array
    {
        $mapped = 0;
        $skipped = 0;
        $users = DB::table('users')->whereRaw('optientry_reserved_address(email)')->orderBy('email')->get(['id', 'email', 'password', 'optinexus_subject']);

        foreach ($users as $user) {
            if (DB::table('service_principals')->where('user_id', $user->id)->exists()) {
                continue; // already registered (this method is safe to run again)
            }

            $problem = $this->mappingProblem($user);
            if (is_string($problem)) {
                $skipped++;
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid7(), 'actor_scope' => 'system', 'action' => 'service_principal.backfill_skipped', 'resource_type' => 'user', 'resource_id' => $user->id,
                    'changes' => json_encode(['before' => null, 'after' => ['email' => $user->email, 'reason' => $problem]]),
                ]);

                continue;
            }

            [$tenantId, $kind, $source] = $problem;
            DB::table('service_principals')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'kind' => $kind, 'source_system' => $source, 'issued_via' => 'BACKFILL',
                'issued_by_label' => 'migration', 'reason' => 'Mapped from the integration user that OptiEntry created for this tenant before the registry existed', 'issued_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'actor_scope' => 'system', 'action' => 'service_principal.issued', 'resource_type' => 'user', 'resource_id' => $user->id,
                'changes' => json_encode(['before' => null, 'after' => ['kind' => $kind, 'email' => $user->email, 'via' => 'BACKFILL']]),
            ]);
            $mapped++;
        }

        return ['mapped' => $mapped, 'skipped' => $skipped];
    }

    /** @return string|array{0:string,1:string,2:string} why the user is not mapped, or the tenant id, kind and source system it is mapped to */
    private function mappingProblem(object $user): string|array
    {
        if ($user->password !== null || $user->optinexus_subject !== null) {
            return 'has_sign_in';
        }

        $address = strtolower((string) $user->email);
        foreach (self::KINDS as $kind => [$source, $localPart]) {
            if (! preg_match('/^'.preg_quote($localPart, '/').'@([a-z0-9-]+)\.'.preg_quote(self::RESERVED_DOMAIN, '/').'$/', $address, $match)) {
                continue;
            }

            $tenantId = DB::table('tenants')->where('code', $match[1])->value('id');
            $memberships = DB::table('tenant_users')->where('user_id', $user->id)->pluck('tenant_id')->all();
            if ($tenantId === null || $memberships !== [$tenantId]) {
                return 'not_a_member_of_exactly_the_tenant_in_its_address';
            }
            $hasRole = DB::table('tenant_user_roles as r')->join('tenant_users as tu', 'tu.id', '=', 'r.tenant_user_id')->where('tu.user_id', $user->id)->exists()
                || DB::table('platform_role_assignments')->where('user_id', $user->id)->exists();
            if ($hasRole) {
                return 'holds_roles';
            }
            if (DB::table('service_principals')->where('tenant_id', $tenantId)->where('kind', $kind)->whereNull('revoked_at')->exists()) {
                return 'tenant_already_has_a_principal_of_this_kind';
            }

            return [$tenantId, $kind, $source];
        }

        return 'address_matches_no_known_kind';
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS users_reserved_address_check ON users');
        DB::unprepared('DROP TRIGGER IF EXISTS users_service_principal_lock ON users');
        DB::unprepared('DROP TRIGGER IF EXISTS tenant_users_service_principal_one_tenant ON tenant_users');
        DB::unprepared('DROP TRIGGER IF EXISTS platform_role_assignments_no_service_principal ON platform_role_assignments');
        DB::unprepared('DROP TRIGGER IF EXISTS tenant_user_roles_no_service_principal ON tenant_user_roles');
        DB::unprepared('DROP TRIGGER IF EXISTS service_principals_no_truncate ON service_principals'); // present in the first version of this migration
        Schema::dropIfExists('service_principals');
        foreach (['users_reserved_address_check', 'service_principals_one_tenant', 'service_principals_no_platform_role', 'service_principals_no_tenant_role', 'service_principals_user_lock',
            'service_principals_validate', 'service_principals_guard'] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS optientry_reserved_address(text)');
    }
};
