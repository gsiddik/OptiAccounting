<?php

namespace App\Domain\Currency\Services;

use App\Domain\Accounting\Services\OperationalSetupService;
use Illuminate\Support\Facades\DB;

/**
 * The posting rules the realised exchange difference needs, as a ready-made set a tenant applies explicitly (same mechanism and guarantees as
 * the OA2/OA3 and asset sets: idempotent, nothing overwritten, editable afterwards by publishing new versions). Kept apart from the others on
 * purpose: a tenant that books in its functional currency only is never asked to map the exchange gain and loss roles.
 *
 * A foreign payment or receipt posts three values (see ForeignPostingService::settle): `carrying` (what the invoices were recognised at),
 * `settlement` (what the bank moves) and the difference, split into `fx_gain` and `fx_loss`; the zero side is skipped.
 */
class FxSetupService
{
    /** event type => [code, name, lines [side, role, component, description]] */
    public const RULES = [
        'VENDOR_PAYMENT_FX' => ['AP-PAYMENT-FX', 'Pembayaran vendor mata uang asing', [
            ['DEBIT', 'ACCOUNTS_PAYABLE', 'carrying', 'Pelunasan utang usaha (nilai tercatat)'], ['DEBIT', 'FX_LOSS', 'fx_loss', 'Rugi selisih kurs'],
            ['CREDIT', 'CASH_BANK_ACCOUNT', 'settlement', 'Kas atau bank'], ['CREDIT', 'FX_GAIN', 'fx_gain', 'Laba selisih kurs'],
        ]],
        'CUSTOMER_RECEIPT_FX' => ['AR-RECEIPT-FX', 'Penerimaan pelanggan mata uang asing', [
            ['DEBIT', 'CASH_BANK_ACCOUNT', 'settlement', 'Kas atau bank'], ['DEBIT', 'FX_LOSS', 'fx_loss', 'Rugi selisih kurs'],
            ['CREDIT', 'ACCOUNTS_RECEIVABLE', 'carrying', 'Pelunasan piutang usaha (nilai tercatat)'], ['CREDIT', 'FX_GAIN', 'fx_gain', 'Laba selisih kurs'],
        ]],
    ];

    public function __construct(private readonly OperationalSetupService $setup) {}

    /**
     * Which foreign settlement events can post today, and which roles still lack an account.
     *
     * @return array{events:list<array<string,mixed>>,unmapped_roles:list<string>}
     */
    public function status(): array
    {
        $roles = collect(self::RULES)->flatMap(fn ($def) => array_column($def[2], 1))->unique()
            ->reject(fn ($role) => DB::table('account_roles')->where('code', $role)->value('binding') === 'DOCUMENT')->values();
        $mapped = DB::table('account_mappings')->where('status', 'ACTIVE')->whereNull('branch_id')->whereNull('business_unit_id')->pluck('account_role');

        return ['events' => $this->setup->status(self::RULES), 'unmapped_roles' => $roles->diff($mapped)->values()->all()];
    }

    /** @return array{created:list<string>,skipped:list<array{event_type:string,reason:string}>} */
    public function applyDefaults(?string $effectiveFrom = null): array
    {
        return $this->setup->applyDefaults($effectiveFrom, self::RULES);
    }
}
