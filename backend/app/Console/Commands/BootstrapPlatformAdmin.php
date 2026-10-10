<?php

namespace App\Console\Commands;

use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\SystemRoleSynchronizer;
use App\Domain\Identity\Models\User;
use App\Support\Database\Micros;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first platform administrator without any password in the repository.
 * The password comes from the environment (OPTIACCOUNTING_BOOTSTRAP_PASSWORD) or a hidden prompt.
 */
class BootstrapPlatformAdmin extends Command
{
    protected $signature = 'optiaccounting:bootstrap-platform-admin
        {email : E-mail address of the administrator}
        {--name=Platform Administrator : Display name}';

    protected $description = 'Create the first platform administrator (password from OPTIACCOUNTING_BOOTSTRAP_PASSWORD or a hidden prompt)';

    public function handle(SystemRoleSynchronizer $roles, AccessCache $cache): int
    {
        $email = (string) $this->argument('email');
        $roles->syncRoles();

        $adminRole = Role::query()->platform()->whereRaw('lower(name) = ?', [strtolower('Platform Administrator')])->firstOrFail();

        $user = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();
        if ($user) {
            if (DB::table('platform_role_assignments')->where('user_id', $user->id)->where('role_id', $adminRole->id)->exists()) {
                $this->info('This user is already a platform administrator. Nothing changed.');

                return self::SUCCESS;
            }
            $this->warn('The user exists; granting the platform administrator role. Their password is unchanged.');
        } else {
            $password = (string) (getenv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD') ?: $this->secret('Password (min 12 chars, mixed case, number)'));
            $validator = Validator::make(['email' => $email, 'password' => $password], [
                'email' => ['required', 'email'],
                'password' => ['required', Password::min(12)->mixedCase()->numbers()],
            ]);
            if ($validator->fails()) {
                $this->error(implode(' ', $validator->errors()->all()));

                return self::FAILURE;
            }

            $user = new User(['name' => (string) $this->option('name'), 'email' => $email]);
            $user->password = $password; // hashed by the cast
            $user->status = User::ACTIVE;
            $user->save();
        }

        $user->platformRoles()->syncWithoutDetaching([$adminRole->id]);
        $cache->touchPlatform();
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(), 'actor_scope' => 'system', 'action' => 'platform_admin.bootstrapped',
            'resource_type' => 'user', 'resource_id' => $user->id, 'occurred_at' => Micros::now(),
        ]);

        $this->info("Platform administrator ready: {$user->email}");

        return self::SUCCESS;
    }
}
