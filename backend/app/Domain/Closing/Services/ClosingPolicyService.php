<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Closing\Models\ClosingPolicyRule;
use App\Domain\Closing\Models\ClosingSetting;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The closing policy of a tenant: what each close check does (BLOCK stops a hard close, WARN only reports, OFF skips the check) and the
 * closing settings (reconciliation tolerance, reopen limits, segregation of closer and reopener, soft close first).
 *
 * The policy is data, the defaults are code: a check without a `closing_policy_rules` row follows CHECKS, and a tenant without a
 * `closing_settings` row gets the strict defaults. The financial invariants are not policy: the three LOCKED checks (balanced trial
 * balance, no submitted / approved journal in the period, the required manual checklist) cannot be relaxed by any row.
 * Every change is audited with the previous and the new effective policy.
 */
class ClosingPolicyService
{
    public const BLOCK = 'BLOCK';

    public const WARN = 'WARN';

    public const OFF = 'OFF';

    /** @var list<string> the checks whose severity is an accounting invariant and therefore always BLOCK */
    public const LOCKED = ['TRIAL_BALANCE', 'PENDING_JOURNALS', 'CHECKLIST'];

    /**
     * Every close check in evaluation order: code => [title, default severity, module whose entitlement the check needs (null = always)].
     *
     * @var array<string,array{0:string,1:string,2:?string}>
     */
    public const CHECKS = [
        'TRIAL_BALANCE' => ['Neraca saldo seimbang', self::BLOCK, null],
        'PENDING_JOURNALS' => ['Jurnal diajukan atau disetujui belum diposting', self::BLOCK, null],
        'DRAFT_JOURNALS' => ['Jurnal draf pada periode', self::WARN, null],
        'PENDING_DOCUMENTS' => ['Dokumen sumber belum diposting', self::WARN, null],
        'AP_RECONCILIATION' => ['Rekonsiliasi utang usaha dengan buku besar', self::BLOCK, 'ACCOUNTING_AP'],
        'AR_RECONCILIATION' => ['Rekonsiliasi piutang usaha dengan buku besar', self::BLOCK, 'ACCOUNTING_AR'],
        'CASH_BANK_RECONCILIATION' => ['Rekonsiliasi kas dan bank dengan buku besar', self::BLOCK, 'ACCOUNTING_CASH_BANK'],
        'FIXED_ASSET_RECONCILIATION' => ['Rekonsiliasi aset tetap dengan buku besar', self::BLOCK, 'ACCOUNTING_FIXED_ASSET'],
        'DEPRECIATION_COMPLETE' => ['Penyusutan periode sudah diposting', self::BLOCK, 'ACCOUNTING_FIXED_ASSET'],
        'TAX_RECONCILIATION' => ['Rekonsiliasi pajak dengan buku besar', self::WARN, 'ACCOUNTING_TAX'],
        'FX_RECONCILIATION' => ['Rekonsiliasi selisih kurs dengan buku besar', self::WARN, 'ACCOUNTING_MULTI_CURRENCY'],
        'PREVIOUS_PERIODS_CLOSED' => ['Periode sebelumnya sudah ditutup', self::WARN, null],
        'REPORT_MAPPING' => ['Saldo akun terpetakan pada laporan keuangan', self::WARN, 'ACCOUNTING_REPORTING'],
        'CHECKLIST' => ['Daftar periksa penutupan wajib selesai', self::BLOCK, null],
    ];

    /** Settings columns and their defaults (strict: tolerance zero, no reopen limit, later periods must stay open). */
    private const SETTING_DEFAULTS = [
        'reconciliation_tolerance' => '0.0000', 'reopen_max_periods_back' => null, 'reopen_requires_later_open' => true,
        'separate_closer_and_reopener' => false, 'require_soft_close_first' => false,
    ];

    public function __construct(private readonly AuditService $audit, private readonly TenantContext $context) {}

    /** @return array<string,string> check code => effective severity */
    public function severities(): array
    {
        $overrides = ClosingPolicyRule::query()->pluck('severity', 'check_code')->all();
        $effective = [];
        foreach (self::CHECKS as $code => [, $default]) {
            $effective[$code] = in_array($code, self::LOCKED, true) ? self::BLOCK : ($overrides[$code] ?? $default);
        }

        return $effective;
    }

    public function severity(string $checkCode): string
    {
        return $this->severities()[$checkCode] ?? throw new DomainException('Unknown close check.', 'CLOSING_CHECK_UNKNOWN', 422, ['check_code' => $checkCode]);
    }

    /** The settings row of the tenant, or an unsaved one holding the defaults. */
    public function settings(): ClosingSetting
    {
        return ClosingSetting::query()->first() ?? (new ClosingSetting)->forceFill(self::SETTING_DEFAULTS);
    }

    /** @return array{checks:list<array<string,mixed>>,settings:array<string,mixed>} */
    public function policy(): array
    {
        $severities = $this->severities();
        $checks = [];
        foreach (self::CHECKS as $code => [$title, $default, $module]) {
            $checks[] = ['check_code' => $code, 'title' => $title, 'default_severity' => $default, 'severity' => $severities[$code], 'locked' => in_array($code, self::LOCKED, true), 'module' => $module];
        }

        return ['checks' => $checks, 'settings' => $this->settingsArray($this->settings())];
    }

    /**
     * @param  list<array{check_code:string,severity:string}>  $rules
     * @param  array<string,mixed>  $settings  any of the closing_settings columns
     * @return array{checks:list<array<string,mixed>>,settings:array<string,mixed>}
     */
    public function update(array $rules, array $settings): array
    {
        return DB::transaction(function () use ($rules, $settings) {
            DB::table('tenants')->where('id', $this->context->tenantId())->lockForUpdate()->first(); // one policy author at a time
            $before = $this->snapshot();

            foreach ($rules as $rule) {
                $this->applyRule((string) $rule['check_code'], (string) $rule['severity']);
            }
            if ($settings !== []) {
                $this->applySettings($settings);
            }

            $after = $this->snapshot();
            if ($before !== $after) {
                $this->audit->record('accounting.closing.policy.updated', 'closing_policy', $this->context->tenantId(), $before, $after);
            }

            return $this->policy();
        });
    }

    private function applyRule(string $code, string $severity): void
    {
        if (! isset(self::CHECKS[$code])) {
            throw new DomainException('Unknown close check.', 'CLOSING_CHECK_UNKNOWN', 422, ['check_code' => $code]);
        }
        if (! in_array($severity, [self::BLOCK, self::WARN, self::OFF], true)) {
            throw new DomainException('A severity is BLOCK, WARN or OFF.', 'CLOSING_SEVERITY_INVALID', 422, ['check_code' => $code]);
        }
        if (in_array($code, self::LOCKED, true) && $severity !== self::BLOCK) {
            throw new DomainException("The check {$code} is an accounting invariant and always blocks the close.", 'CLOSING_CHECK_LOCKED', 422, ['check_code' => $code]);
        }

        if ($severity === self::CHECKS[$code][1]) {
            ClosingPolicyRule::query()->where('check_code', $code)->delete(); // back to the default: no override row

            return;
        }

        $row = ClosingPolicyRule::query()->where('check_code', $code)->first() ?? (new ClosingPolicyRule)->forceFill(['check_code' => $code]);
        $row->severity = $severity;
        $row->updated_by = $this->context->user()?->id;
        $row->save();
    }

    /** @param array<string,mixed> $settings */
    private function applySettings(array $settings): void
    {
        $row = ClosingSetting::query()->first() ?? (new ClosingSetting)->forceFill(self::SETTING_DEFAULTS);
        if (array_key_exists('reconciliation_tolerance', $settings)) {
            $settings['reconciliation_tolerance'] = Money::str(Money::parse($settings['reconciliation_tolerance'], 4, 'reconciliation_tolerance'));
        }
        $row->forceFill(array_intersect_key($settings, self::SETTING_DEFAULTS));
        $row->updated_by = $this->context->user()?->id;
        $row->save();
    }

    /** @return array<string,mixed> the effective policy as it is audited */
    private function snapshot(): array
    {
        return ['severities' => $this->severities(), 'settings' => $this->settingsArray($this->settings())];
    }

    /** @return array<string,mixed> */
    private function settingsArray(ClosingSetting $settings): array
    {
        $out = [];
        foreach (array_keys(self::SETTING_DEFAULTS) as $key) {
            $out[$key] = $key === 'reconciliation_tolerance' ? Money::str($settings->{$key}) : $settings->{$key};
        }

        return $out;
    }
}
