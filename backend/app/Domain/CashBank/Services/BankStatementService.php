<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\CashBank\Models\BankStatement;
use App\Domain\CashBank\Models\BankStatementItem;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Manual bank reconciliation. A statement is what the bank says (a closing balance and its lines, entered by the user). Reconciling
 * means linking each statement line to the posted journal line it corresponds to (MATCHED), or flagging it with a reason (EXCEPTION).
 * The ledger is never written: a difference stays visible as a difference and no adjusting journal is created to force a match. The
 * statement balance is evidence, not GL truth; the book balance is always read from posted journal lines.
 *
 * Amounts of statement lines are signed from the book's side: a deposit is positive (it matches a debit of the account's GL account),
 * a withdrawal negative (it matches a credit). On the first statement the opening balance is entered as a line matched to the
 * opening journal.
 *
 * Reconciliation equation at the statement date:  (statement balance + unmatched book net) - (book balance + exception net) = 0.
 */
class BankStatementService
{
    public function __construct(
        private readonly CashBankAccountService $cashAccounts,
        private readonly CashBankLedgerService $ledger,
        private readonly DocumentScope $scope,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    public function query(array $filter = []): Builder
    {
        $count = fn (string $status) => "(select count(*) from bank_statement_items i where i.tenant_id = bank_statements.tenant_id and i.bank_statement_id = bank_statements.id and i.status = '{$status}')";
        $query = BankStatement::query()->with('cashBankAccount')->addSelect('bank_statements.*')
            ->selectRaw($count('UNMATCHED').' as unmatched_items')->selectRaw($count('MATCHED').' as matched_items')->selectRaw($count('EXCEPTION').' as exception_items');
        $this->scope->restrict($query->getQuery(), 'bank_statements');

        return $query
            ->when($filter['cash_bank_account_id'] ?? null, fn ($q, $v) => $q->where('bank_statements.cash_bank_account_id', $v))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('bank_statements.status', $v))
            ->when($filter['from'] ?? null, fn ($q, $v) => $q->whereDate('bank_statements.statement_date', '>=', $v))
            ->when($filter['to'] ?? null, fn ($q, $v) => $q->whereDate('bank_statements.statement_date', '<=', $v))
            ->orderByDesc('bank_statements.statement_date')->orderByDesc('bank_statements.created_at');
    }

    public function load(BankStatement $statement): BankStatement
    {
        $statement->load(['cashBankAccount', 'items.matcher', 'creator', 'completer', 'branch']);
        $statement->setAttribute('summary', $this->figures($statement));

        return $statement;
    }

    /**
     * The reconciliation figures of a statement. While it is OPEN they are live; once COMPLETED the frozen evidence is returned
     * (the counts stay live, they cannot change). Differences are reported, never adjusted.
     *
     * @return array<string,mixed>
     */
    public function figures(BankStatement $statement): array
    {
        $account = CashBankAccount::query()->findOrFail($statement->cash_bank_account_id);
        $items = BankStatementItem::query()->where('bank_statement_id', $statement->id)->get();
        $count = fn (string $status) => $items->where('status', $status)->count();
        $itemsNet = Money::sum($items->pluck('amount')->all());
        $closing = BigDecimal::of($statement->closing_balance);

        if ($statement->status === BankStatement::COMPLETED) {
            $book = BigDecimal::of($statement->book_balance);
            $unmatched = BigDecimal::of($statement->unmatched_book_net);
            $exception = BigDecimal::of($statement->exception_statement_net);
            $difference = BigDecimal::of($statement->unexplained_difference);
            $unmatchedBookLines = null;
        } else {
            $date = $statement->statement_date->toDateString();
            $book = BigDecimal::of($this->cashAccounts->bookBalances([$account->account_id], $date)[$account->account_id]);
            $line = $this->ledger->unmatched($account->account_id, $date);
            $unmatched = BigDecimal::of((string) $line->net);
            $unmatchedBookLines = (int) $line->lines;
            $exception = Money::sum($items->where('status', BankStatementItem::EXCEPTION)->pluck('amount')->all());
            $difference = $closing->plus($unmatched)->minus($book->plus($exception));
        }

        return [
            'book_balance' => Money::str($book), 'statement_balance' => Money::str($closing), 'unmatched_book_net' => Money::str($unmatched), 'unmatched_book_lines' => $unmatchedBookLines,
            'exception_statement_net' => Money::str($exception), 'unexplained_difference' => Money::str($difference), 'status' => $difference->isZero() ? 'RECONCILED' : 'DIFFERENCE',
            'book_minus_statement' => Money::str($book->minus($closing)), 'items_net' => Money::str($itemsNet),
            'statement_consistent' => $statement->opening_balance === null ? null : BigDecimal::of($statement->opening_balance)->plus($itemsNet)->isEqualTo($closing),
            'unmatched_items' => $count(BankStatementItem::UNMATCHED), 'matched_items' => $count(BankStatementItem::MATCHED), 'exception_items' => $count(BankStatementItem::EXCEPTION),
        ];
    }

    // ------------------------------------------------------------------------------------------------ statement

    public function create(array $data, User $actor): BankStatement
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data, $actor) {
            $account = $this->bankAccount($data['cash_bank_account_id'] ?? null);
            $scale = $this->scale();

            $statement = new BankStatement($this->header($data, $scale, true));
            $statement->forceFill([
                'cash_bank_account_id' => $account->id, 'currency' => $account->currency, 'status' => BankStatement::OPEN, 'created_by' => $actor->id,
                'branch_id' => $account->branch_id, 'business_unit_id' => $account->business_unit_id,
            ]);
            $statement->save();
            $this->writeItems($statement, (array) ($data['items'] ?? []), $scale);
            $this->audit->record('cash_bank.statement.created', 'bank_statement', $statement->id, null, $this->summary($statement->refresh()) + ['items' => count((array) ($data['items'] ?? []))]);

            return $this->load($statement);
        }));
    }

    public function update(BankStatement $statement, array $data): BankStatement
    {
        return $this->guarded(fn () => DB::transaction(function () use ($statement, $data) {
            $statement = $this->openStatement($statement);
            $before = $this->summary($statement);
            $statement->fill($this->header($data, $this->scale(), false, $statement));
            $statement->save();
            $this->audit->record('cash_bank.statement.updated', 'bank_statement', $statement->id, $before, $this->summary($statement->refresh()));

            return $this->load($statement);
        }));
    }

    public function delete(BankStatement $statement): void
    {
        DB::transaction(function () use ($statement) {
            $statement = $this->openStatement($statement);
            if (BankStatementItem::query()->where('bank_statement_id', $statement->id)->where('status', BankStatementItem::MATCHED)->exists()) {
                throw new DomainException('Unmatch the items before deleting the statement.', 'BANK_STATEMENT_HAS_MATCHES', 409);
            }
            $before = $this->summary($statement);
            BankStatementItem::query()->where('bank_statement_id', $statement->id)->delete();
            $statement->delete();
            $this->audit->record('cash_bank.statement.deleted', 'bank_statement', $statement->id, $before, null);
        });
    }

    /**
     * Complete the reconciliation: every line must be matched or flagged, and the figures at that moment are frozen as evidence. A
     * remaining difference is recorded as it is; no journal is created or changed, and the statement can no longer change.
     */
    public function complete(BankStatement $statement, User $actor): BankStatement
    {
        return DB::transaction(function () use ($statement, $actor) {
            $statement = $this->openStatement($statement);
            $open = BankStatementItem::query()->where('bank_statement_id', $statement->id)->where('status', BankStatementItem::UNMATCHED)->count();
            if ($open > 0) {
                throw new DomainException('Match every statement line or flag it as an exception before completing the reconciliation.', 'BANK_STATEMENT_HAS_UNMATCHED_ITEMS', 409, ['unmatched_items' => $open]);
            }

            $figures = $this->figures($statement);
            $statement->forceFill([
                'status' => BankStatement::COMPLETED, 'completed_by' => $actor->id, 'completed_at' => now(), 'book_balance' => $figures['book_balance'],
                'unmatched_book_net' => $figures['unmatched_book_net'], 'exception_statement_net' => $figures['exception_statement_net'], 'unexplained_difference' => $figures['unexplained_difference'],
            ])->save();
            $this->audit->record('cash_bank.statement.completed', 'bank_statement', $statement->id, ['status' => BankStatement::OPEN], [
                'status' => BankStatement::COMPLETED, 'reference' => $statement->reference, 'statement_date' => $statement->statement_date->toDateString(),
                'statement_balance' => $figures['statement_balance'], 'book_balance' => $figures['book_balance'], 'unexplained_difference' => $figures['unexplained_difference'],
            ]);

            return $this->load($statement->refresh());
        });
    }

    // ------------------------------------------------------------------------------------------------ items

    /** @param list<array<string,mixed>> $items */
    public function addItems(BankStatement $statement, array $items): BankStatement
    {
        return DB::transaction(function () use ($statement, $items) {
            $statement = $this->openStatement($statement);
            $this->writeItems($statement, $items, $this->scale());
            $this->audit->record('cash_bank.statement.items_added', 'bank_statement', $statement->id, null, ['count' => count($items), 'reference' => $statement->reference]);

            return $this->load($statement);
        });
    }

    public function updateItem(BankStatementItem $item, array $data): BankStatement
    {
        return DB::transaction(function () use ($item, $data) {
            [$statement, $item] = $this->lockItem($item);
            if ($item->status === BankStatementItem::MATCHED) {
                throw new DomainException('Unmatch the item before changing it.', 'BANK_ITEM_MATCHED', 409);
            }
            $before = $item->only(['item_date', 'description', 'reference', 'amount', 'status']);
            $item->fill($this->itemFields($data, $this->scale(), $statement, true));
            $item->save();
            $this->audit->record('cash_bank.statement.item_updated', 'bank_statement', $statement->id, $before, $item->refresh()->only(['item_date', 'description', 'reference', 'amount', 'status']));

            return $this->load($statement);
        });
    }

    public function deleteItem(BankStatementItem $item): BankStatement
    {
        return DB::transaction(function () use ($item) {
            [$statement, $item] = $this->lockItem($item);
            if ($item->status === BankStatementItem::MATCHED) {
                throw new DomainException('Unmatch the item before deleting it.', 'BANK_ITEM_MATCHED', 409);
            }
            $before = $item->only(['line_number', 'item_date', 'description', 'amount']);
            $item->delete();
            $this->audit->record('cash_bank.statement.item_deleted', 'bank_statement', $statement->id, $before, null);

            return $this->load($statement);
        });
    }

    /** Link a statement line to the posted journal line it corresponds to. Same amount and direction; a book line is matched at most once. */
    public function match(BankStatementItem $item, string $journalLineId, User $actor): BankStatement
    {
        try {
            return DB::transaction(function () use ($item, $journalLineId, $actor) {
                [$statement, $item] = $this->lockItem($item);
                if ($item->status === BankStatementItem::MATCHED) {
                    if ($item->matched_journal_line_id === $journalLineId) {
                        return $this->load($statement); // the same match asked twice
                    }
                    throw new DomainException('Unmatch the item before matching it to another book line.', 'BANK_ITEM_MATCHED', 409);
                }
                $account = CashBankAccount::query()->findOrFail($statement->cash_bank_account_id);

                $line = DB::table('journal_lines as l')->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
                    ->where('l.tenant_id', $this->context->tenantId())->where('l.id', $journalLineId)->where('l.account_id', $account->account_id)->where('j.status', 'POSTED')
                    ->first(['l.id', 'l.debit', 'l.credit', 'j.journal_number', 'j.posting_date']);
                if ($line === null) {
                    throw new DomainException('That is not a posted line of this bank account.', 'BANK_BOOK_LINE_NOT_FOUND', 422, ['field' => 'journal_line_id']);
                }
                $amount = BigDecimal::of($item->amount);
                $bookAmount = $amount->isPositive() ? BigDecimal::of($line->debit) : BigDecimal::of($line->credit);
                if (! $bookAmount->isEqualTo($amount->abs())) {
                    throw new DomainException('The statement line and the book line must have the same amount and direction.', 'BANK_MATCH_AMOUNT_MISMATCH', 422, [
                        'statement_amount' => Money::str($amount), 'book_debit' => Money::str($line->debit), 'book_credit' => Money::str($line->credit),
                    ]);
                }
                if (DB::table('bank_statement_items')->where('matched_journal_line_id', $line->id)->exists()) {
                    throw new DomainException('This book line is already matched to a statement line.', 'BANK_BOOK_LINE_ALREADY_MATCHED', 409);
                }

                $before = ['status' => $item->status];
                $item->forceFill(['status' => BankStatementItem::MATCHED, 'matched_journal_line_id' => $line->id, 'matched_by' => $actor->id, 'matched_at' => now()])->save();
                $this->audit->record('cash_bank.statement.item_matched', 'bank_statement', $statement->id, $before, [
                    'line_number' => $item->line_number, 'amount' => $item->amount, 'journal_number' => $line->journal_number, 'journal_line_id' => $line->id,
                ]);

                return $this->load($statement);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505' && str_contains($e->getMessage(), 'bank_statement_items_line_unique')) {
                throw new DomainException('This book line is already matched to a statement line.', 'BANK_BOOK_LINE_ALREADY_MATCHED', 409);
            }
            throw $e;
        }
    }

    /** Take a statement line back to UNMATCHED (from MATCHED or EXCEPTION). */
    public function unmatch(BankStatementItem $item, User $actor): BankStatement
    {
        return DB::transaction(function () use ($item, $actor) {
            [$statement, $item] = $this->lockItem($item);
            if ($item->status === BankStatementItem::UNMATCHED) {
                return $this->load($statement);
            }
            $before = ['status' => $item->status, 'journal_line_id' => $item->matched_journal_line_id, 'notes' => $item->notes];
            $item->forceFill(['status' => BankStatementItem::UNMATCHED, 'matched_journal_line_id' => null, 'matched_by' => null, 'matched_at' => null, 'notes' => null])->save();
            $this->audit->record('cash_bank.statement.item_unmatched', 'bank_statement', $statement->id, $before, ['status' => BankStatementItem::UNMATCHED, 'line_number' => $item->line_number, 'by' => $actor->id]);

            return $this->load($statement);
        });
    }

    /** Flag a statement line that has no book counterpart (yet) with the reason; it is carried in the figures as a bank item not in the books. */
    public function markException(BankStatementItem $item, string $notes, User $actor): BankStatement
    {
        return DB::transaction(function () use ($item, $notes, $actor) {
            [$statement, $item] = $this->lockItem($item);
            if ($item->status === BankStatementItem::MATCHED) {
                throw new DomainException('Unmatch the item before flagging it as an exception.', 'BANK_ITEM_MATCHED', 409);
            }
            if (trim($notes) === '') {
                throw new DomainException('State why this line is an exception.', 'BANK_EXCEPTION_REASON_REQUIRED', 422, ['field' => 'notes']);
            }
            $before = ['status' => $item->status];
            $item->forceFill(['status' => BankStatementItem::EXCEPTION, 'notes' => mb_substr(trim($notes), 0, 500), 'matched_by' => null, 'matched_at' => null])->save();
            $this->audit->record('cash_bank.statement.item_flagged', 'bank_statement', $statement->id, $before, ['status' => BankStatementItem::EXCEPTION, 'line_number' => $item->line_number, 'notes' => $item->notes, 'by' => $actor->id]);

            return $this->load($statement);
        });
    }

    /**
     * Book lines a statement line could be matched with: unmatched, same amount and direction, posted close to the line's date.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(BankStatementItem $item, int $days = 30): array
    {
        $statement = BankStatement::query()->findOrFail($item->bank_statement_id);
        $account = CashBankAccount::query()->findOrFail($statement->cash_bank_account_id);
        $amount = BigDecimal::of($item->amount);
        $date = $item->item_date->toDateString();

        $rows = DB::table('journal_lines as l')->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->leftJoin('bank_statement_items as i', 'i.matched_journal_line_id', '=', 'l.id')
            ->where('l.tenant_id', $this->context->tenantId())->where('l.account_id', $account->account_id)->where('j.status', 'POSTED')->whereNull('i.id')
            ->where($amount->isPositive() ? 'l.debit' : 'l.credit', Money::str($amount->abs()))
            ->whereRaw('abs(j.posting_date - ?::date) <= ?', [$date, $days])
            ->orderByRaw('abs(j.posting_date - ?::date)', [$date])->orderBy('j.journal_number')->limit(20)
            ->get(['l.id as journal_line_id', 'j.journal_number', 'j.posting_date', 'l.description', 'l.reference', 'l.debit', 'l.credit', 'j.source_type', 'j.source_id']);

        return $rows->map(fn ($r) => [
            'journal_line_id' => $r->journal_line_id, 'journal_number' => $r->journal_number, 'posting_date' => substr((string) $r->posting_date, 0, 10), 'description' => $r->description,
            'reference' => $r->reference, 'amount' => Money::str($amount->isPositive() ? $r->debit : $r->credit), 'source_type' => $r->source_type, 'source_id' => $r->source_id,
            'days_apart' => abs((int) round((strtotime(substr((string) $r->posting_date, 0, 10)) - strtotime($date)) / 86400)),
        ])->all();
    }

    // ------------------------------------------------------------------------------------------------ internals

    private function scale(): int
    {
        return (int) (DB::table('accounting_profiles')->where('tenant_id', $this->context->tenantId())->value('currency_scale') ?? 2);
    }

    /** A bank account (not a cash box) the user can see; statements exist for banks only. */
    private function bankAccount(?string $id): CashBankAccount
    {
        $account = $id ? CashBankAccount::query()->find($id) : null;
        if (! $account || ! $this->scope->visible($account)) {
            throw new DomainException('The bank account does not exist.', 'CASH_BANK_ACCOUNT_NOT_FOUND', 422, ['field' => 'cash_bank_account_id']);
        }
        if ($account->kind !== CashBankAccount::BANK) {
            throw new DomainException('A bank statement belongs to a bank account, not a cash box.', 'BANK_STATEMENT_NOT_BANK_ACCOUNT', 422, ['field' => 'cash_bank_account_id']);
        }

        return $account;
    }

    /** The statement, locked for the change and OPEN (a completed reconciliation is final). */
    private function openStatement(BankStatement $statement): BankStatement
    {
        $statement = BankStatement::query()->lockForUpdate()->findOrFail($statement->id);
        if ($statement->status !== BankStatement::OPEN) {
            throw new DomainException('A completed bank reconciliation cannot change.', 'BANK_STATEMENT_COMPLETED', 409);
        }

        return $statement;
    }

    /** @return array{0:BankStatement,1:BankStatementItem} the OPEN statement (locked) and the item (locked) */
    private function lockItem(BankStatementItem $item): array
    {
        $statement = BankStatement::query()->lockForUpdate()->findOrFail($item->bank_statement_id);
        if ($statement->status !== BankStatement::OPEN) {
            throw new DomainException('A completed bank reconciliation cannot change.', 'BANK_STATEMENT_COMPLETED', 409);
        }

        return [$statement, BankStatementItem::query()->lockForUpdate()->findOrFail($item->id)];
    }

    /** @return array<string,mixed> */
    private function header(array $data, int $scale, bool $creating, ?BankStatement $existing = null): array
    {
        $out = collect($data)->only(['reference', 'statement_date', 'period_start', 'notes'])->all();
        if ($creating && (empty($data['reference']) || empty($data['statement_date']))) {
            throw new DomainException('The statement reference and date are required.', 'BANK_STATEMENT_INVALID', 422);
        }
        if (isset($out['reference'])) {
            $out['reference'] = trim($out['reference']);
        }
        if ($creating || array_key_exists('closing_balance', $data)) {
            $out['closing_balance'] = Money::str(Money::parseSigned($data['closing_balance'] ?? null, $scale, 'closing_balance'));
            if ($creating && ! array_key_exists('closing_balance', $data)) {
                throw new DomainException('The closing balance is required.', 'BANK_STATEMENT_INVALID', 422, ['field' => 'closing_balance']);
            }
        }
        if (array_key_exists('opening_balance', $data)) {
            $out['opening_balance'] = $data['opening_balance'] === null ? null : Money::str(Money::parseSigned($data['opening_balance'], $scale, 'opening_balance'));
        }
        $start = $out['period_start'] ?? $existing?->period_start?->toDateString();
        $end = $out['statement_date'] ?? $existing?->statement_date->toDateString();
        if ($start !== null && $end !== null && $start > $end) {
            throw new DomainException('The statement period cannot start after its date.', 'BANK_STATEMENT_INVALID', 422, ['field' => 'period_start']);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function itemFields(array $data, int $scale, BankStatement $statement, bool $partial = false): array
    {
        $out = collect($data)->only(['item_date', 'description', 'reference'])->all();
        if (! $partial && (empty($data['item_date']) || trim((string) ($data['description'] ?? '')) === '')) {
            throw new DomainException('Every statement line needs a date and a description.', 'BANK_ITEM_INVALID', 422);
        }
        if (! $partial || array_key_exists('amount', $data)) {
            $amount = Money::parseSigned($data['amount'] ?? null, $scale, 'amount');
            if ($amount->isZero()) {
                throw new DomainException('A statement line needs a non-zero amount (deposits positive, withdrawals negative).', 'BANK_ITEM_AMOUNT_INVALID', 422, ['field' => 'amount']);
            }
            $out['amount'] = Money::str($amount);
        }
        if (isset($out['description'])) {
            $out['description'] = mb_substr(trim($out['description']), 0, 255);
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $items */
    private function writeItems(BankStatement $statement, array $items, int $scale): void
    {
        if (count($items) > 1000) {
            throw new DomainException('A statement takes at most 1000 lines per request.', 'BANK_ITEM_TOO_MANY', 422);
        }
        $number = (int) BankStatementItem::query()->where('bank_statement_id', $statement->id)->max('line_number');
        foreach (array_values($items) as $row) {
            $item = new BankStatementItem($this->itemFields((array) $row, $scale, $statement));
            $item->forceFill(['bank_statement_id' => $statement->id, 'line_number' => ++$number, 'status' => BankStatementItem::UNMATCHED])->save();
        }
    }

    private function summary(BankStatement $statement): array
    {
        return $statement->only(['cash_bank_account_id', 'reference', 'statement_date', 'closing_balance', 'status']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new DomainException('This bank account already has a statement with this reference.', 'BANK_STATEMENT_REFERENCE_TAKEN', 422);
            }
            throw $e;
        }
    }
}
