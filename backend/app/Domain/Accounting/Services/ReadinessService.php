<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Accounting readiness: one place that says what is still missing before a tenant can post, and the guard every posting
 * path calls. Readiness is about accounting configuration; it is independent of the tenant lifecycle and of entitlement
 * (those are decided by EffectiveAccess before a request gets here).
 */
class ReadinessService
{
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{status:string,ready:bool,can_activate:bool,checks:list<array{code:string,label:string,required:bool,done:bool,detail:string,action:string}>}
     */
    public function report(): array
    {
        $profile = AccountingProfile::query()->first();
        $openYear = FiscalYear::query()->where('status', FiscalYear::OPEN)->exists();
        $openPeriod = AccountingPeriod::query()->where('status', AccountingPeriod::OPEN)->exists();
        $postable = Account::query()->where('status', Account::ACTIVE)->where('is_postable', true)->count();
        $unmapped = $this->unmappedRoles();
        $opening = DB::table('opening_balances')->whereIn('status', ['DRAFT', 'POSTED'])->value('status');

        $checks = [
            $this->check('PROFILE', 'Profil akuntansi', true, $profile !== null, $profile ? 'Kerangka akuntansi: '.$profile->framework : 'Pilih kerangka akuntansi dan simpan profil.', 'profile'),
            $this->check('CURRENCY', 'Mata uang fungsional', true, $profile !== null && $profile->functional_currency !== null, $profile ? 'Mata uang fungsional: '.$profile->functional_currency : 'Tetapkan mata uang fungsional pada profil.', 'profile'),
            $this->check('FISCAL_YEAR', 'Tahun fiskal', true, $openYear, $openYear ? 'Ada tahun fiskal yang terbuka.' : 'Buat tahun fiskal lalu buka.', 'fiscal-years'),
            $this->check('PERIOD', 'Periode akuntansi', true, $openPeriod, $openPeriod ? 'Ada periode yang terbuka untuk posting.' : 'Buka minimal satu periode akuntansi.', 'fiscal-years'),
            $this->check('COA', 'Bagan akun', true, $postable >= 2, $postable >= 2 ? "{$postable} akun posting aktif." : 'Buat minimal dua akun posting aktif atau terapkan templat.', 'accounts'),
            $this->check('MAPPINGS', 'Pemetaan akun untuk aturan posting', true, $unmapped === [], $unmapped === [] ? 'Semua peran akun pada aturan terbit sudah dipetakan.' : 'Peran belum dipetakan: '.implode(', ', $unmapped), 'account-mappings'),
            $this->check('OPENING', 'Saldo awal (jika ada)', false, $opening === 'POSTED', match ($opening) {
                'POSTED' => 'Saldo awal sudah diposting.', 'DRAFT' => 'Saldo awal masih draf.', default => 'Opsional: lewati bila pembukuan dimulai dari nol.',
            }, 'opening-balance'),
        ];

        $canActivate = collect($checks)->every(fn ($c) => ! $c['required'] || $c['done']);

        return [
            'status' => $profile?->status ?? self::NOT_CONFIGURED,
            'ready' => $profile?->isReady() ?? false,
            'can_activate' => $profile !== null && ! $profile->isReady() && $canActivate,
            'checks' => $checks,
        ];
    }

    /** @return list<array<string,mixed>> required steps that are not done */
    public function blockingChecks(): array
    {
        return array_values(array_filter($this->report()['checks'], fn ($c) => $c['required'] && ! $c['done']));
    }

    /**
     * The guard of every posting path (manual, opening, reversal, event). Returns the profile so callers can use its policy.
     *
     * @throws DomainException
     */
    public function assertCanPost(string $postingDate, string $journalType): AccountingProfile
    {
        $profile = AccountingProfile::query()->first();

        if (! $profile || ! $profile->isReady()) {
            throw new DomainException('Accounting is not activated for this organization.', 'ACCOUNTING_NOT_READY', 409, ['status' => $profile?->status ?? self::NOT_CONFIGURED]);
        }

        if ($profile->cutover_date !== null && $journalType !== 'OPENING' && $postingDate < $profile->cutover_date->toDateString()) {
            throw new DomainException('The posting date is before the cutover date of the opening balance.', 'POSTING_BEFORE_CUTOVER', 422, ['cutover_date' => $profile->cutover_date->toDateString()]);
        }

        return $profile;
    }

    /** @return list<string> roles used by a published rule that have no active tenant-level mapping */
    private function unmappedRoles(): array
    {
        $used = DB::table('posting_rule_lines as l')->join('posting_rules as r', function ($j) {
            $j->on('r.id', '=', 'l.posting_rule_id')->on('r.tenant_id', '=', 'l.tenant_id');
        })->where('r.tenant_id', $this->context->tenantId())->where('r.status', 'PUBLISHED')->distinct()->pluck('l.account_role')->all();

        if ($used === []) {
            return [];
        }

        $mapped = DB::table('account_mappings')->where('tenant_id', $this->context->tenantId())->where('status', 'ACTIVE')
            ->whereNull('branch_id')->whereNull('business_unit_id')->pluck('account_role')->all();
        $documentBound = DB::table('account_roles')->where('binding', 'DOCUMENT')->pluck('code')->all(); // the source document names these accounts

        return array_values(array_diff($used, $mapped, $documentBound));
    }

    private function check(string $code, string $label, bool $required, bool $done, string $detail, string $action): array
    {
        return compact('code', 'label', 'required', 'done', 'detail', 'action');
    }
}
