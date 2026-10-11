<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** The one Accounting Profile of a tenant: framework metadata, functional currency, workflow policy. */
class AccountingProfileService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ReadinessService $readiness,
        private readonly TenantContext $context,
    ) {}

    public function current(): ?AccountingProfile
    {
        return AccountingProfile::query()->first();
    }

    /** Create or update. Functional currency and amount scale are frozen once the first journal is posted (status LOCKED). */
    public function save(array $data): AccountingProfile
    {
        return DB::transaction(function () use ($data) {
            $profile = AccountingProfile::query()->lockForUpdate()->first();
            $creating = $profile === null;
            $profile ??= new AccountingProfile;

            if ($profile->status === AccountingProfile::LOCKED) {
                $currencyChange = isset($data['functional_currency']) && $data['functional_currency'] !== $profile->functional_currency;
                $scaleChange = isset($data['currency_scale']) && (int) $data['currency_scale'] !== (int) $profile->currency_scale;
                if ($currencyChange || $scaleChange) {
                    throw new DomainException('Functional currency and amount precision cannot change after the first journal was posted.', 'ACCOUNTING_PROFILE_LOCKED', 409);
                }
            }

            // foreign currencies and their rates are defined against the functional currency: it does not change underneath them
            if (! $creating && isset($data['functional_currency']) && $data['functional_currency'] !== $profile->functional_currency
                && (DB::table('currencies')->where('tenant_id', $profile->tenant_id)->exists() || DB::table('exchange_rates')->where('tenant_id', $profile->tenant_id)->exists())) {
                throw new DomainException('Remove the foreign currencies and exchange rates before changing the functional currency.', 'FUNCTIONAL_CURRENCY_HAS_RATES', 409);
            }

            $before = $creating ? null : $profile->only(array_keys($data));
            $profile->fill($data);
            if ($creating) {
                $profile->status = AccountingProfile::CONFIGURING;
                $profile->currency_scale ??= 2;
            }
            $profile->save();

            $this->audit->record($creating ? 'accounting.profile.created' : 'accounting.profile.updated', 'accounting_profile', $profile->id, $before, $profile->only(array_keys($data)));

            return $profile;
        });
    }

    /** CONFIGURING -> READY, only when every required setup step is done. */
    public function activate(): AccountingProfile
    {
        return DB::transaction(function () {
            $profile = AccountingProfile::query()->lockForUpdate()->first()
                ?? throw new DomainException('Save the accounting profile first.', 'ACCOUNTING_NOT_CONFIGURED', 409);

            if ($profile->isReady()) {
                return $profile;
            }

            $blocking = $this->readiness->blockingChecks();
            if ($blocking !== []) {
                throw new DomainException('Accounting setup is not complete.', 'ACCOUNTING_SETUP_INCOMPLETE', 409, ['checks' => $blocking]);
            }

            $profile->status = AccountingProfile::READY;
            $profile->activated_at = now();
            $profile->save();
            $this->audit->record('accounting.profile.activated', 'accounting_profile', $profile->id, ['status' => AccountingProfile::CONFIGURING], ['status' => AccountingProfile::READY]);

            return $profile;
        });
    }

    /** Called by the posting engine inside the posting transaction: the first posted journal freezes currency and precision. */
    public function lockOnFirstPosting(string $tenantId): void
    {
        DB::table('accounting_profiles')->where('tenant_id', $tenantId)->where('status', '<>', AccountingProfile::LOCKED)
            ->update(['status' => AccountingProfile::LOCKED, 'locked_at' => now(), 'updated_at' => now()]);
    }

    public function setCutoverDate(string $tenantId, string $date): void
    {
        DB::table('accounting_profiles')->where('tenant_id', $tenantId)->update(['cutover_date' => $date, 'updated_at' => now()]);
    }
}
