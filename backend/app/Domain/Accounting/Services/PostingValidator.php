<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Support\Money;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The validation every journal goes through, manual or automatic: line shape, exact decimals, tenant-owned active posting
 * accounts, tenant-owned active dimensions and (for posting) the double-entry balance. Nothing here trusts client totals.
 * The database repeats the critical rules as triggers.
 */
class PostingValidator
{
    /**
     * @param  list<array<string,mixed>>  $lines  account_id, debit|credit, description?, reference?, branch_id?, business_unit_id?, cost_center_id?, dimensions?[]
     * @param  bool  $balanced  true for submit / post: at least two lines and Σ debit = Σ credit > 0
     * @return array{lines:list<array<string,mixed>>,total_debit:BigDecimal,total_credit:BigDecimal}
     */
    public function normalize(array $lines, AccountingProfile $profile, string $journalType, bool $balanced, bool $lockAccounts = false): array
    {
        $scale = (int) $profile->currency_scale;
        $accounts = $this->accounts($lines, $lockAccounts);
        $branches = Branch::query()->whereIn('id', $this->ids($lines, 'branch_id'))->get()->keyBy('id');
        $units = BusinessUnit::query()->whereIn('id', $this->ids($lines, 'business_unit_id'))->get()->keyBy('id');
        $centers = CostCenter::query()->whereIn('id', $this->ids($lines, 'cost_center_id'))->get()->keyBy('id');
        $external = DB::table('dimension_types')->where('kind', 'EXTERNAL')->where('status', 'ACTIVE')->pluck('code')->all();

        $normalized = [];
        $debits = [];
        $credits = [];
        foreach (array_values($lines) as $index => $line) {
            $n = $index + 1;
            $debit = Money::parse($line['debit'] ?? null, $scale, 'debit', $n);
            $credit = Money::parse($line['credit'] ?? null, $scale, 'credit', $n);
            if ($debit->isZero() === $credit->isZero()) {
                throw new DomainException('A line carries either a debit or a credit amount greater than zero, never both and never neither.', 'LINE_AMOUNT_INVALID', 422, ['line' => $n]);
            }

            $account = $accounts[$line['account_id'] ?? ''] ?? throw new DomainException('The account does not exist.', 'ACCOUNT_NOT_FOUND', 422, ['line' => $n]);
            $this->assertPostable($account, $profile, $journalType, $n);

            $branch = $this->found($branches, $line['branch_id'] ?? null, 'branch', $n);
            $unit = $this->found($units, $line['business_unit_id'] ?? null, 'business unit', $n);
            $center = $this->found($centers, $line['cost_center_id'] ?? null, 'cost center', $n);
            if ($unit && $branch && $unit->branch_id !== null && $unit->branch_id !== $branch->id) {
                throw new DomainException('The business unit belongs to another branch.', 'DIMENSION_MISMATCH', 422, ['line' => $n]);
            }
            if ($center && $branch && $center->branch_id !== null && $center->branch_id !== $branch->id) {
                throw new DomainException('The cost center belongs to another branch.', 'DIMENSION_MISMATCH', 422, ['line' => $n]);
            }

            $dimensions = [];
            foreach ((array) ($line['dimensions'] ?? []) as $dimension) {
                $type = (string) ($dimension['type'] ?? '');
                if (! in_array($type, $external, true) || isset($dimensions[$type]) || trim((string) ($dimension['reference_id'] ?? '')) === '') {
                    throw new DomainException("Dimension {$type} is not available on this line.", 'DIMENSION_INVALID', 422, ['line' => $n]);
                }
                $dimensions[$type] = ['type' => $type, 'reference_id' => mb_substr((string) $dimension['reference_id'], 0, 64), 'reference_label' => isset($dimension['reference_label']) ? mb_substr((string) $dimension['reference_label'], 0, 255) : null];
            }

            $transaction = $this->transaction($line['transaction'] ?? null, $profile, $journalType, $n);
            $fxDifference = (bool) ($line['fx_difference'] ?? false);
            if ($fxDifference && ($transaction !== null || in_array($journalType, ['MANUAL', 'OPENING'], true))) {
                throw new DomainException('A realised exchange difference is a functional-currency line of a system posting.', 'LINE_FX_DIFFERENCE_INVALID', 422, ['line' => $n]);
            }

            $debits[] = $debit;
            $credits[] = $credit;
            $normalized[] = [
                'account_id' => $account->id, 'description' => isset($line['description']) ? mb_substr((string) $line['description'], 0, 255) : null,
                'reference' => isset($line['reference']) ? mb_substr((string) $line['reference'], 0, 100) : null,
                'debit' => $debit, 'credit' => $credit, 'transaction' => $transaction, 'fx_difference' => $fxDifference,
                'branch_id' => $branch?->id, 'business_unit_id' => $unit?->id, 'cost_center_id' => $center?->id,
                'dimensions' => array_values($dimensions),
            ];
        }

        $totalDebit = Money::sum($debits);
        $totalCredit = Money::sum($credits);

        if ($balanced) {
            if (count($normalized) < 2) {
                throw new DomainException('A journal needs at least two lines.', 'JOURNAL_TOO_FEW_LINES', 422);
            }
            if (! $totalDebit->isEqualTo($totalCredit)) {
                throw new DomainException('The journal does not balance: total debit must equal total credit.', 'JOURNAL_UNBALANCED', 422, ['total_debit' => Money::str($totalDebit), 'total_credit' => Money::str($totalCredit)]);
            }
            if ($totalDebit->isZero()) {
                throw new DomainException('A journal with a zero total is not meaningful.', 'JOURNAL_UNBALANCED', 422);
            }
        }

        return ['lines' => $normalized, 'total_debit' => $totalDebit, 'total_credit' => $totalCredit];
    }

    /**
     * The foreign leg of a line (system postings and their reversals only): {currency, amount, rate} next to the functional debit or credit.
     * The rate is the snapshot of the document the line belongs to; the functional amount is authoritative for the ledger.
     *
     * @return array{currency:string,amount:BigDecimal,rate:BigDecimal}|null
     */
    private function transaction(mixed $raw, AccountingProfile $profile, string $journalType, int $line): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (in_array($journalType, ['MANUAL', 'OPENING'], true) || ! is_array($raw)) {
            throw new DomainException('Manual and opening journals are booked in the functional currency.', 'LINE_TRANSACTION_NOT_ALLOWED', 422, ['line' => $line]);
        }
        $currency = (string) ($raw['currency'] ?? '');
        if (! preg_match('/^[A-Z]{3}$/', $currency) || $currency === $profile->functional_currency) {
            throw new DomainException('The transaction currency must be a foreign ISO currency code.', 'LINE_TRANSACTION_INVALID', 422, ['line' => $line, 'field' => 'currency']);
        }
        $amount = Money::parse($raw['amount'] ?? null, 4, 'transaction amount', $line);
        try {
            $rate = BigDecimal::of((string) ($raw['rate'] ?? ''));
        } catch (\Throwable) {
            throw new DomainException('The exchange rate must be a positive decimal.', 'LINE_TRANSACTION_INVALID', 422, ['line' => $line, 'field' => 'rate']);
        }
        if ($amount->isLessThanOrEqualTo(0) || ! $rate->isPositive() || $rate->getScale() > 10) {
            throw new DomainException('The transaction amount and exchange rate must be positive.', 'LINE_TRANSACTION_INVALID', 422, ['line' => $line]);
        }

        return ['currency' => $currency, 'amount' => $amount, 'rate' => $rate];
    }

    private function assertPostable(Account $account, AccountingProfile $profile, string $journalType, int $line): void
    {
        if ($account->status !== Account::ACTIVE) {
            throw new DomainException("Account {$account->code} is inactive.", 'ACCOUNT_INACTIVE', 422, ['line' => $line, 'account' => $account->code]);
        }
        if (! $account->is_postable) {
            throw new DomainException("Account {$account->code} is a header account and cannot be posted to.", 'ACCOUNT_NOT_POSTABLE', 422, ['line' => $line, 'account' => $account->code]);
        }
        if ($account->is_control && $journalType === 'MANUAL') {
            throw new DomainException("Account {$account->code} is a control account; it accepts postings from its subledger only.", 'ACCOUNT_CONTROL_RESTRICTED', 422, ['line' => $line, 'account' => $account->code]);
        }
        if ($account->currency !== null && $account->currency !== $profile->functional_currency) {
            throw new DomainException("Account {$account->code} is restricted to {$account->currency}.", 'ACCOUNT_CURRENCY_MISMATCH', 422, ['line' => $line, 'account' => $account->code]);
        }
    }

    /** @return Collection<string,Account> */
    private function accounts(array $lines, bool $lock)
    {
        $query = Account::query()->whereIn('id', $this->ids($lines, 'account_id'));
        if ($lock) {
            $query->sharedLock(); // a concurrent deactivation waits for this posting
        }

        return $query->get()->keyBy('id');
    }

    /** @return list<string> */
    private function ids(array $lines, string $key): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($l) => is_string($l[$key] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $l[$key]) ? $l[$key] : null, $lines))));
    }

    private function found($map, mixed $id, string $label, int $line): mixed
    {
        if ($id === null || $id === '') {
            return null;
        }
        $row = $map[$id] ?? throw new DomainException("The {$label} does not exist.", 'DIMENSION_NOT_FOUND', 422, ['line' => $line]);
        if (($row->status ?? 'ACTIVE') !== 'ACTIVE') {
            throw new DomainException("The {$label} is inactive.", 'DIMENSION_INACTIVE', 422, ['line' => $line]);
        }

        return $row;
    }
}
