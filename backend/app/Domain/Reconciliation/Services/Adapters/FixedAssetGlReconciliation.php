<?php

namespace App\Domain\Reconciliation\Services\Adapters;

use App\Domain\FixedAsset\Services\AssetReconciliationService;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use Brick\Math\BigDecimal;

/**
 * The fixed asset register against the general ledger (OA4 `AssetReconciliationService`, module ACCOUNTING_FIXED_ASSET), as of the date:
 *
 *   COST                       compared. The acquisition cost of the assets on the books against the asset accounts of the ledger.
 *   ACCUMULATED_DEPRECIATION   compared. The depreciation of the posted runs against the accumulated depreciation accounts.
 *   NET_BOOK_VALUE             compared. Cost minus accumulated depreciation on both sides: the consistency of the two above.
 *
 * COST and ACCUMULATED_DEPRECIATION carry the per-account rows in `breakdown`; one row beyond the tolerance makes its component MISMATCH
 * (exactly like the legacy `reconciled` flag), and any mismatching component makes the result MISMATCH. The headline is the net book value
 * (register against ledger), so offsetting differences are visible in the components, never absorbed.
 */
class FixedAssetGlReconciliation extends ReconciliationAdapter
{
    public function __construct(private readonly AssetReconciliationService $service) {}

    public function type(): string
    {
        return ReconciliationResult::FIXED_ASSET_GL;
    }

    public function module(): string
    {
        return 'ACCOUNTING_FIXED_ASSET';
    }

    public function reconcile(string $asOf, BigDecimal $tolerance): ReconciliationResult
    {
        $report = $this->service->report($asOf);
        $totals = $report['totals'];

        $components = [
            $this->sideComponent('COST', 'Asset cost register against the asset accounts', $report['cost'], $totals['register_cost'], $totals['ledger_cost'], $tolerance),
            $this->sideComponent('ACCUMULATED_DEPRECIATION', 'Accumulated depreciation register against the accumulated depreciation accounts', $report['accumulated_depreciation'],
                $totals['register_accumulated'], $totals['ledger_accumulated'], $tolerance),
            $this->component('NET_BOOK_VALUE', 'Net book value: register against ledger', BigDecimal::of($totals['register_net_book_value']), BigDecimal::of($totals['ledger_net_book_value']), $tolerance),
        ];

        return $this->outcome($asOf, $tolerance, BigDecimal::of($totals['register_net_book_value']), BigDecimal::of($totals['ledger_net_book_value']), $components, [
            'Compared: the register of the assets on the books (capitalized and not disposed by the date, depreciation of the posted runs) against the posted ledger balance of the asset and accumulated depreciation accounts.',
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $rows  the legacy per-account rows (register, ledger, difference)
     * @return array<string,mixed>
     */
    private function sideComponent(string $code, string $label, array $rows, string $register, string $ledger, BigDecimal $tolerance): array
    {
        $breakdown = array_map(fn ($row) => [
            'account' => $row['account'], 'asset_count' => $row['asset_count'], 'source_balance' => $row['register'], 'gl_balance' => $row['ledger'],
            'difference' => BigDecimal::of($row['register'])->minus($row['ledger'])->toScale(4)->__toString(),
            'status' => $this->within(BigDecimal::of($row['register'])->minus($row['ledger']), $tolerance) ? ReconciliationResult::MATCHED : ReconciliationResult::MISMATCH,
        ], $rows);
        $component = $this->component($code, $label, BigDecimal::of($register), BigDecimal::of($ledger), $tolerance, false, ['breakdown' => $breakdown]);
        if (collect($breakdown)->contains('status', ReconciliationResult::MISMATCH)) {
            $component['status'] = ReconciliationResult::MISMATCH;
        }

        return $component;
    }
}
