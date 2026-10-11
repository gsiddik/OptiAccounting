<?php

namespace App\Domain\FixedAsset\Services;

use App\Domain\Accounting\Services\AccountMappingService;
use App\Domain\Accounting\Services\OperationalSetupService;
use Illuminate\Support\Facades\DB;

/**
 * The posting rules the fixed asset events need, as a ready-made set a tenant applies explicitly (the same mechanism and guarantees as the
 * OA2/OA3 set: idempotent, nothing overwritten, editable afterwards by publishing new versions). Kept apart from the OA2/OA3 set on
 * purpose: a tenant that does not use fixed assets must not be asked to map asset roles before it can activate accounting.
 */
class AssetSetupService
{
    /** event type => [code, name, lines [side, role, component, description]] */
    public const RULES = [
        'ASSET_CAPITALIZED' => ['ASSET-CAPITALIZATION', 'Kapitalisasi aset tetap', [
            ['DEBIT', 'FIXED_ASSET', 'cost', 'Aset tetap'], ['CREDIT', 'DOCUMENT_ACCOUNT', 'cost', 'Akun sumber (utang, bank atau penampung)'],
        ]],
        'DEPRECIATION_RECOGNIZED' => ['ASSET-DEPRECIATION', 'Penyusutan aset tetap', [
            ['DEBIT', 'DEPRECIATION_EXPENSE', 'amount', 'Beban penyusutan'], ['CREDIT', 'ACCUMULATED_DEPRECIATION', 'amount', 'Akumulasi penyusutan'],
        ]],
        'ASSET_DISPOSED' => ['ASSET-DISPOSAL', 'Pelepasan aset tetap', [
            ['DEBIT', 'ACCUMULATED_DEPRECIATION', 'accumulated', 'Penghapusan akumulasi penyusutan'], ['DEBIT', 'DOCUMENT_ACCOUNT', 'proceeds', 'Hasil pelepasan'],
            ['DEBIT', 'ASSET_DISPOSAL_GAIN_LOSS', 'loss', 'Rugi pelepasan aset'], ['CREDIT', 'FIXED_ASSET', 'cost', 'Penghapusan aset tetap'],
            ['CREDIT', 'ASSET_DISPOSAL_GAIN_LOSS', 'gain', 'Laba pelepasan aset'],
        ]],
    ];

    public function __construct(private readonly OperationalSetupService $setup, private readonly AccountMappingService $mappings) {}

    /**
     * Which asset events can post today, and which roles still lack an account.
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
