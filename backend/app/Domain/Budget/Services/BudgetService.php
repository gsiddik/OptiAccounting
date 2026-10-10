<?php

namespace App\Domain\Budget\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Services\DimensionGuard;
use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Services\DocumentWorkflow;
use App\Domain\Accounting\Services\SegregationOfDuties;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Budget\Models\Budget;
use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetVersion;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Budget management (OA4 batch A). A budget is planning data: nothing in here posts, reads or writes a journal.
 *
 * - The budget (one fiscal year) and its versions have separate lifecycles. A version moves DRAFT > SUBMITTED > APPROVED > ACTIVE > SUPERSEDED
 *   through the shared DocumentWorkflow (segregation of duties from the accounting profile), and only a draft has editable lines.
 * - Activating a version closes the effective window of the previously active one the day before, under the budget's row lock, so exactly
 *   one version is effective on any date and an approved version is never rewritten (a revision is a new version).
 * - A line plans one account (a header account stands for its whole subtree) in one period, optionally split by branch, business unit and
 *   cost center. Lines of one version never overlap: no two lines may cover the same account/period/dimension combination, where a
 *   dimension left empty covers every value. That is what lets actuals be matched to at most one line.
 */
class BudgetService
{
    public const MAX_LINES = 6000;

    private const TRANSITIONS = ['DRAFT' => ['ACTIVE', 'CANCELLED'], 'ACTIVE' => ['CLOSED', 'CANCELLED']];

    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly DimensionGuard $dimensions,
        private readonly DocumentScope $scope,
        private readonly SegregationOfDuties $sod,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    public function query(array $filter = []): Builder
    {
        return Budget::query()->with(['fiscalYear', 'responsible', 'versions:id,budget_id,version_number,label,status,effective_from,effective_until'])
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['fiscal_year_id'] ?? null, fn ($q, $v) => $q->where('fiscal_year_id', $v))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
            })
            ->orderByDesc('created_at');
    }

    public function load(Budget $budget): Budget
    {
        return $budget->load(['fiscalYear', 'responsible', 'creator', 'versions', 'transitions.actor']);
    }

    public function loadVersion(BudgetVersion $version): BudgetVersion
    {
        $version->load(['budget.fiscalYear', 'creator', 'transitions.actor']);
        $lines = $this->visibleLines($version->id)->with(['account', 'period', 'branch', 'businessUnit', 'costCenter'])->get()
            ->sortBy(fn (BudgetLine $l) => sprintf('%03d|%s', $l->period->number, $l->account->code))->values();
        $version->setRelation('lines', $lines);
        $version->setAttribute('lines_total', Money::str(Money::sum($lines->pluck('amount'))));
        $version->setAttribute('lines_complete', $this->scope->isTenantWide());

        return $version;
    }

    public function sod(BudgetVersion $version, string $userId): array
    {
        return $this->sod->documentAllowed($version, $userId, $this->workflow->profile());
    }

    /** The lines of a version the user may see (data scope on branch / business unit; a line without them is tenant-wide). */
    public function visibleLines(string $versionId): Builder
    {
        $query = BudgetLine::query()->where('budget_version_id', $versionId);
        $this->scope->restrict($query->getQuery(), 'budget_lines');

        return $query;
    }

    /** The version in force on a date: ACTIVE, or SUPERSEDED while its window still covers the date. */
    public function effectiveVersion(Budget $budget, string $asOf): ?BudgetVersion
    {
        return BudgetVersion::query()->where('budget_id', $budget->id)->whereIn('status', [BudgetVersion::ACTIVE, BudgetVersion::SUPERSEDED])
            ->whereDate('effective_from', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->first();
    }

    // ------------------------------------------------------------------------------------------------ budget

    public function create(array $data, User $actor): Budget
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data, $actor) {
            $profile = $this->workflow->profile();
            $year = FiscalYear::query()->find($data['fiscal_year_id'] ?? null)
                ?? throw new DomainException('The fiscal year does not exist.', 'FISCAL_YEAR_NOT_FOUND', 422, ['field' => 'fiscal_year_id']);
            if ($year->status === FiscalYear::CLOSED) {
                throw new DomainException('A closed fiscal year cannot take a new budget.', 'BUDGET_FISCAL_YEAR_CLOSED', 422, ['field' => 'fiscal_year_id']);
            }

            $budget = new Budget(['code' => $this->code($data['code'] ?? ''), 'name' => $this->name($data['name'] ?? ''), 'description' => $this->text($data['description'] ?? null, 500)]);
            $budget->forceFill([
                'fiscal_year_id' => $year->id, 'currency' => $profile->functional_currency, 'status' => Budget::DRAFT, 'created_by' => $actor->id,
                'responsible_user_id' => $this->responsible($data['responsible_user_id'] ?? null),
            ])->save();
            $this->workflow->created($budget, $actor->id);
            $this->audit->record('budget.created', Budget::DOCUMENT_TYPE, $budget->id, null, $this->summary($budget));

            return $this->load($budget->refresh());
        }));
    }

    public function update(Budget $budget, array $data): Budget
    {
        return DB::transaction(function () use ($budget, $data) {
            $budget = Budget::query()->lockForUpdate()->findOrFail($budget->id);
            if (! in_array($budget->status, [Budget::DRAFT, Budget::ACTIVE], true)) {
                throw new DomainException("A {$budget->status} budget cannot be edited.", 'BUDGET_NOT_EDITABLE', 409, ['status' => $budget->status]);
            }
            $before = $this->summary($budget);

            if (array_key_exists('name', $data)) {
                $budget->name = $this->name($data['name']);
            }
            if (array_key_exists('description', $data)) {
                $budget->description = $this->text($data['description'], 500);
            }
            if (array_key_exists('responsible_user_id', $data)) {
                $budget->responsible_user_id = $this->responsible($data['responsible_user_id']);
            }
            $budget->save();
            $this->audit->record('budget.updated', Budget::DOCUMENT_TYPE, $budget->id, $before, $this->summary($budget));

            return $this->load($budget);
        });
    }

    /** DRAFT > ACTIVE: the budget is open for versions to be activated. */
    public function open(Budget $budget, User $actor): Budget
    {
        return $this->moveBudget($budget, Budget::ACTIVE, $actor, null, function (Budget $b) use ($actor) {
            $b->activated_by = $actor->id;
            $b->activated_at = now();
        });
    }

    /** ACTIVE > CLOSED: the figures stay reportable; no version can be added or activated. */
    public function close(Budget $budget, User $actor): Budget
    {
        return $this->moveBudget($budget, Budget::CLOSED, $actor, null, function (Budget $b) use ($actor) {
            $b->closed_by = $actor->id;
            $b->closed_at = now();
        });
    }

    /** Only while no version was ever approved (the database repeats the rule); otherwise close it. */
    public function cancel(Budget $budget, User $actor, string $reason): Budget
    {
        return $this->moveBudget($budget, Budget::CANCELLED, $actor, $reason, function (Budget $b) use ($actor, $reason) {
            $b->cancelled_by = $actor->id;
            $b->cancelled_at = now();
            $b->cancel_reason = $reason;
        }, function (Budget $b) {
            $approved = BudgetVersion::query()->where('budget_id', $b->id)->whereIn('status', ['APPROVED', 'ACTIVE', 'SUPERSEDED'])->exists();
            if ($approved) {
                throw new DomainException('A budget with an approved version cannot be cancelled; close it instead.', 'BUDGET_HAS_APPROVED_VERSION', 409);
            }
            // Open drafts of a cancelled budget are cancelled with it so nothing stays half-finished.
            BudgetVersion::query()->where('budget_id', $b->id)->whereIn('status', ['DRAFT', 'SUBMITTED', 'REJECTED'])->get()->each(function (BudgetVersion $v) {
                $from = $v->status;
                $v->forceFill(['status' => 'CANCELLED', 'cancelled_by' => $this->context->user()?->id, 'cancelled_at' => now(), 'cancel_reason' => 'Budget cancelled'])->save();
                $this->workflow->log($v, $from, 'CANCELLED', $this->context->user()?->id, 'Budget cancelled');
            });
        });
    }

    // ------------------------------------------------------------------------------------------------ versions

    /** A new draft version, optionally a copy of an earlier one (a revision). The budget row lock serializes version numbering. */
    public function createVersion(Budget $budget, array $data, User $actor): BudgetVersion
    {
        return DB::transaction(function () use ($budget, $data, $actor) {
            $budget = Budget::query()->lockForUpdate()->findOrFail($budget->id);
            if (! in_array($budget->status, [Budget::DRAFT, Budget::ACTIVE], true)) {
                throw new DomainException("A {$budget->status} budget takes no new versions.", 'BUDGET_NOT_EDITABLE', 409, ['status' => $budget->status]);
            }

            $base = null;
            if (! empty($data['copy_from_version_id'])) {
                $base = BudgetVersion::query()->where('budget_id', $budget->id)->find($data['copy_from_version_id'])
                    ?? throw new DomainException('The version to copy does not exist in this budget.', 'BUDGET_VERSION_NOT_FOUND', 422, ['field' => 'copy_from_version_id']);
                if ($base->status === 'CANCELLED') {
                    throw new DomainException('A cancelled version cannot be copied.', 'BUDGET_VERSION_NOT_COPYABLE', 422, ['field' => 'copy_from_version_id']);
                }
                if (! $this->scope->isTenantWide()) {
                    throw new DomainException('Copying a version needs tenant-wide data scope: a scoped user cannot see every line.', 'BUDGET_COPY_REQUIRES_FULL_SCOPE', 403);
                }
            }

            $number = (int) BudgetVersion::query()->where('budget_id', $budget->id)->max('version_number') + 1;
            $version = new BudgetVersion(['label' => $this->text($data['label'] ?? null, 100) ?? "Versi {$number}", 'description' => $this->text($data['description'] ?? null, 500)]);
            $version->forceFill(['budget_id' => $budget->id, 'version_number' => $number, 'base_version_id' => $base?->id, 'status' => BudgetVersion::DRAFT, 'created_by' => $actor->id])->save();

            $copied = 0;
            if ($base) {
                $copied = DB::affectingStatement(
                    'insert into budget_lines (id, tenant_id, budget_id, budget_version_id, account_id, accounting_period_id, amount, branch_id, business_unit_id, cost_center_id, description, created_by, created_at, updated_at)
                     select gen_random_uuid(), tenant_id, budget_id, ?, account_id, accounting_period_id, amount, branch_id, business_unit_id, cost_center_id, description, ?, now(), now()
                     from budget_lines where tenant_id = ? and budget_version_id = ?',
                    [$version->id, $actor->id, $budget->tenant_id, $base->id],
                );
            }

            $this->workflow->created($version, $actor->id);
            $this->audit->record('budget.version.created', BudgetVersion::DOCUMENT_TYPE, $version->id, null, $this->versionSummary($version) + ['copied_from' => $base?->version_number, 'lines_copied' => $copied]);

            return $this->loadVersion($version->refresh());
        });
    }

    public function updateVersion(BudgetVersion $version, array $data): BudgetVersion
    {
        return DB::transaction(function () use ($version, $data) {
            $version = $this->lockDraft($version);
            $before = $this->versionSummary($version);
            if (array_key_exists('label', $data)) {
                $label = $this->text($data['label'], 100);
                if ($label === null) {
                    throw new DomainException('The version label is required.', 'LABEL_REQUIRED', 422, ['field' => 'label']);
                }
                $version->label = $label;
            }
            if (array_key_exists('description', $data)) {
                $version->description = $this->text($data['description'], 500);
            }
            $version->save();
            $this->audit->record('budget.version.updated', BudgetVersion::DOCUMENT_TYPE, $version->id, $before, $this->versionSummary($version));

            return $this->loadVersion($version);
        });
    }

    public function submit(BudgetVersion $version, User $actor): BudgetVersion
    {
        return $this->loadVersion($this->workflow->submit($version, $actor, function (BudgetVersion $v) {
            $this->assertBudgetOpenForWork($v);
            if (! BudgetLine::query()->where('budget_version_id', $v->id)->exists()) {
                throw new DomainException('A budget version without lines cannot be submitted.', 'BUDGET_VERSION_EMPTY', 422);
            }
        }));
    }

    public function approve(BudgetVersion $version, User $actor): BudgetVersion
    {
        return $this->loadVersion($this->workflow->approve($version, $actor, fn (BudgetVersion $v) => $this->assertBudgetOpenForWork($v)));
    }

    public function reject(BudgetVersion $version, User $actor, string $reason): BudgetVersion
    {
        return $this->loadVersion($this->workflow->reject($version, $actor, $reason));
    }

    public function reopen(BudgetVersion $version, User $actor): BudgetVersion
    {
        return $this->loadVersion($this->workflow->reopen($version, $actor));
    }

    public function cancelVersion(BudgetVersion $version, User $actor, string $reason): BudgetVersion
    {
        return $this->loadVersion($this->workflow->cancel($version, $actor, $reason));
    }

    /**
     * APPROVED > ACTIVE. The budget row is locked first, so two activations (or an activation and a close) serialize; the previously
     * active version is superseded with its window closed the day before this one starts. The first version starts with the fiscal
     * year unless a start date is given; a revision must start after the previous one started and inside the fiscal year.
     */
    public function activate(BudgetVersion $version, User $actor, ?string $effectiveFrom = null): BudgetVersion
    {
        return DB::transaction(function () use ($version, $actor, $effectiveFrom) {
            $budget = Budget::query()->lockForUpdate()->findOrFail(BudgetVersion::query()->findOrFail($version->id)->budget_id);
            $version = BudgetVersion::query()->lockForUpdate()->findOrFail($version->id);

            if ($version->status !== 'APPROVED') {
                throw new DomainException("A {$version->status} budget version cannot be activated; only an approved one can.", $version->status === BudgetVersion::ACTIVE ? 'BUDGET_VERSION_ALREADY_ACTIVE' : 'BUDGET_VERSION_NOT_APPROVED', 409, ['status' => $version->status]);
            }
            if ($budget->status !== Budget::ACTIVE) {
                throw new DomainException('Only a version of an active budget can be activated; open the budget first.', 'BUDGET_NOT_ACTIVE', 409, ['status' => $budget->status]);
            }

            $year = FiscalYear::query()->findOrFail($budget->fiscal_year_id);
            $current = BudgetVersion::query()->where('budget_id', $budget->id)->where('status', BudgetVersion::ACTIVE)->lockForUpdate()->first();
            $from = $effectiveFrom ?? ($current ? $this->defaultRevisionStart($current, $year) : $year->start_date->toDateString());

            if ($from < $year->start_date->toDateString() || $from > $year->end_date->toDateString()) {
                throw new DomainException('A version must take effect inside the fiscal year of its budget.', 'BUDGET_VERSION_WINDOW_INVALID', 422, ['field' => 'effective_from', 'fiscal_year_start' => $year->start_date->toDateString(), 'fiscal_year_end' => $year->end_date->toDateString()]);
            }
            if ($current && $from <= $current->effective_from->toDateString()) {
                throw new DomainException('A revision must take effect after the day the active version started.', 'BUDGET_VERSION_WINDOW_INVALID', 422, ['field' => 'effective_from', 'active_since' => $current->effective_from->toDateString()]);
            }
            if (! $current && BudgetVersion::query()->where('budget_id', $budget->id)->where('status', BudgetVersion::SUPERSEDED)->exists()) {
                throw new DomainException('This budget has no active version to supersede.', 'BUDGET_VERSION_WINDOW_INVALID', 409);
            }

            if ($current) {
                $until = Carbon::parse($from)->subDay()->toDateString();
                $current->forceFill(['status' => BudgetVersion::SUPERSEDED, 'effective_until' => $until, 'superseded_at' => now(), 'superseded_by_version_id' => $version->id])->save();
                $this->workflow->log($current, BudgetVersion::ACTIVE, BudgetVersion::SUPERSEDED, $actor->id, "Replaced by version {$version->version_number}");
                $this->audit->record('budget.version.superseded', BudgetVersion::DOCUMENT_TYPE, $current->id, ['status' => 'ACTIVE'], ['status' => 'SUPERSEDED', 'effective_until' => $until, 'replaced_by_version' => $version->version_number]);
            }

            $version->forceFill(['status' => BudgetVersion::ACTIVE, 'effective_from' => $from, 'activated_by' => $actor->id, 'activated_at' => now()])->save();
            $this->workflow->log($version, 'APPROVED', BudgetVersion::ACTIVE, $actor->id);
            $this->audit->record('budget.version.activated', BudgetVersion::DOCUMENT_TYPE, $version->id, ['status' => 'APPROVED'], ['status' => 'ACTIVE', 'effective_from' => $from, 'version_number' => $version->version_number, 'budget' => $budget->code]);

            return $this->loadVersion($version->refresh());
        });
    }

    // ------------------------------------------------------------------------------------------------ lines

    /**
     * Replace every line of a draft version at once (the grid editor). It deletes lines, so it needs tenant-wide data scope: a scoped
     * user could not see, and therefore must not drop, the lines outside their scope.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    public function replaceLines(BudgetVersion $version, array $rows, User $actor): BudgetVersion
    {
        return DB::transaction(function () use ($version, $rows, $actor) {
            $version = $this->lockDraft($version);
            if (! $this->scope->isTenantWide()) {
                throw new DomainException('Replacing all lines needs tenant-wide data scope; add or edit single lines instead.', 'BUDGET_SCOPE_REPLACE_FORBIDDEN', 403);
            }
            if (count($rows) > self::MAX_LINES) {
                throw new DomainException('A version can have at most '.self::MAX_LINES.' lines.', 'BUDGET_TOO_MANY_LINES', 422);
            }

            $budget = Budget::query()->findOrFail($version->budget_id);
            $context = $this->context($budget);
            $lines = [];
            foreach (array_values($rows) as $i => $row) {
                $lines[] = $this->prepareRow($row, $i + 1, $context, null);
            }
            $this->assertNoOverlap($lines, $context['parents']);

            $before = BudgetLine::query()->where('budget_version_id', $version->id)->count();
            BudgetLine::query()->where('budget_version_id', $version->id)->delete();
            $now = now();
            foreach (array_chunk($lines, 500) as $chunk) {
                DB::table('budget_lines')->insert(array_map(fn (array $l) => $this->row($version, $l, $actor->id, $now), $chunk));
            }
            $this->audit->record('budget.version.lines_replaced', BudgetVersion::DOCUMENT_TYPE, $version->id, ['lines' => $before], ['lines' => count($lines), 'total' => Money::str(Money::sum(array_column($lines, 'amount')))]);

            return $this->loadVersion($version);
        });
    }

    /** @param array<string,mixed> $row */
    public function addLine(BudgetVersion $version, array $row, User $actor): BudgetVersion
    {
        return DB::transaction(function () use ($version, $row, $actor) {
            $version = $this->lockDraft($version);
            $budget = Budget::query()->findOrFail($version->budget_id);
            $context = $this->context($budget);
            $line = $this->prepareRow($row, 1, $context, $actor);

            $existing = BudgetLine::query()->where('budget_version_id', $version->id)->count();
            if ($existing >= self::MAX_LINES) {
                throw new DomainException('A version can have at most '.self::MAX_LINES.' lines.', 'BUDGET_TOO_MANY_LINES', 422);
            }
            $this->assertNoOverlap([...$this->existing($version->id, $line['period_id']), $line], $context['parents']);

            DB::table('budget_lines')->insert($this->row($version, $line, $actor->id, now()));
            $this->audit->record('budget.version.line_added', BudgetVersion::DOCUMENT_TYPE, $version->id, null, $this->lineSummary($line));

            return $this->loadVersion($version);
        });
    }

    /** @param array<string,mixed> $row */
    public function updateLine(BudgetVersion $version, BudgetLine $line, array $row, User $actor): BudgetVersion
    {
        return DB::transaction(function () use ($version, $line, $row, $actor) {
            $version = $this->lockDraft($version);
            $line = $this->lineOf($version, $line);
            $this->assertWritable($line->branch_id, $line->business_unit_id, $line->created_by);

            $budget = Budget::query()->findOrFail($version->budget_id);
            $context = $this->context($budget);
            $merged = $row + [
                'account_id' => $line->account_id, 'accounting_period_id' => $line->accounting_period_id, 'amount' => $line->amount,
                'branch_id' => $line->branch_id, 'business_unit_id' => $line->business_unit_id, 'cost_center_id' => $line->cost_center_id, 'description' => $line->description,
            ];
            $prepared = $this->prepareRow($merged, 1, $context, $actor);
            $this->assertNoOverlap([...array_values(array_filter($this->existing($version->id, $prepared['period_id']), fn (array $l) => $l['id'] !== $line->id)), $prepared], $context['parents']);

            $before = $this->lineSummary($this->asRow($line));
            DB::table('budget_lines')->where('tenant_id', $line->tenant_id)->where('id', $line->id)->update([
                'account_id' => $prepared['account_id'], 'accounting_period_id' => $prepared['period_id'], 'amount' => Money::str($prepared['amount']),
                'branch_id' => $prepared['branch_id'], 'business_unit_id' => $prepared['business_unit_id'], 'cost_center_id' => $prepared['cost_center_id'],
                'description' => $prepared['description'], 'updated_at' => now(),
            ]);
            $this->audit->record('budget.version.line_updated', BudgetVersion::DOCUMENT_TYPE, $version->id, $before, $this->lineSummary($prepared));

            return $this->loadVersion($version);
        });
    }

    public function removeLine(BudgetVersion $version, BudgetLine $line): BudgetVersion
    {
        return DB::transaction(function () use ($version, $line) {
            $version = $this->lockDraft($version);
            $line = $this->lineOf($version, $line);
            $this->assertWritable($line->branch_id, $line->business_unit_id, $line->created_by);
            $summary = $this->lineSummary($this->asRow($line));
            $line->delete();
            $this->audit->record('budget.version.line_removed', BudgetVersion::DOCUMENT_TYPE, $version->id, $summary, null);

            return $this->loadVersion($version);
        });
    }

    // ------------------------------------------------------------------------------------------------ line preparation

    /** Lookups shared by every row of one request: the account hierarchy, the fiscal year's periods, the currency scale. */
    private function context(Budget $budget): array
    {
        return [
            'budget' => $budget,
            'parents' => Account::query()->pluck('parent_id', 'id')->all(),
            'accounts' => Account::query()->get(['id', 'code', 'status', 'account_type'])->keyBy('id'),
            'periods' => AccountingPeriod::query()->where('fiscal_year_id', $budget->fiscal_year_id)->pluck('id')->flip()->all(),
            'scale' => (int) $this->workflow->profile()->currency_scale,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{id:?string,account_id:string,period_id:string,amount:BigDecimal,branch_id:?string,business_unit_id:?string,cost_center_id:?string,description:?string}
     */
    private function prepareRow(array $row, int $n, array $context, ?User $writer): array
    {
        $accountId = (string) ($row['account_id'] ?? '');
        $account = $context['accounts'][$accountId] ?? throw new DomainException('The account does not exist.', 'ACCOUNT_NOT_FOUND', 422, ['field' => 'account_id', 'line' => $n]);
        if ($account->status !== Account::ACTIVE) {
            throw new DomainException("Account {$account->code} is inactive.", 'ACCOUNT_INACTIVE', 422, ['field' => 'account_id', 'line' => $n, 'account' => $account->code]);
        }

        $periodId = (string) ($row['accounting_period_id'] ?? $row['period_id'] ?? '');
        if (! array_key_exists($periodId, $context['periods'])) {
            throw new DomainException('The period does not belong to the fiscal year of this budget.', 'BUDGET_PERIOD_INVALID', 422, ['field' => 'accounting_period_id', 'line' => $n]);
        }

        $amount = Money::parse($row['amount'] ?? null, $context['scale'], 'amount', $n);
        if (! array_key_exists('amount', $row) || $row['amount'] === null || $row['amount'] === '') {
            throw new DomainException('The amount is required.', 'AMOUNT_INVALID', 422, ['field' => 'amount', 'line' => $n]);
        }

        $dims = $this->dimensions->resolve($row['branch_id'] ?? null, $row['business_unit_id'] ?? null, $row['cost_center_id'] ?? null, $n);
        if ($writer !== null) {
            $this->assertWritable($dims['branch_id'], $dims['business_unit_id'], $writer->id);
        }

        return [
            'id' => $row['id'] ?? null, 'account_id' => $account->id, 'period_id' => $periodId, 'amount' => $amount,
            'branch_id' => $dims['branch_id'], 'business_unit_id' => $dims['business_unit_id'], 'cost_center_id' => $dims['cost_center_id'],
            'description' => $this->text($row['description'] ?? null, 255),
        ];
    }

    /**
     * No two lines of one version may cover the same account, period and dimension values. Accounts overlap when one is the other or
     * its ancestor (a header covers its subtree); a dimension left empty covers every value of it.
     *
     * @param  list<array<string,mixed>>  $lines
     * @param  array<string,?string>  $parents  account id => parent id
     */
    private function assertNoOverlap(array $lines, array $parents): void
    {
        $exact = [];  // period|account => lines whose account is exactly this
        $cover = [];  // period|account => lines whose account is this or below it
        foreach ($lines as $i => $line) {
            $chain = $this->chain($line['account_id'], $parents);
            $related = [];
            foreach ($chain as $ancestor) {
                foreach ($exact[$line['period_id'].'|'.$ancestor] ?? [] as $other) {
                    $related[] = $other;
                }
            }
            foreach ($cover[$line['period_id'].'|'.$line['account_id']] ?? [] as $other) {
                $related[] = $other;
            }
            foreach ($related as $other) {
                if (self::dimensionsOverlap($line, $other)) {
                    throw new DomainException('Budget lines overlap: an account (or its group) is planned twice for the same period and dimensions.', 'BUDGET_LINE_OVERLAP', 422, ['line' => $i + 1, 'account_id' => $line['account_id'], 'period_id' => $line['period_id']]);
                }
            }
            $exact[$line['period_id'].'|'.$line['account_id']][] = $line;
            foreach ($chain as $ancestor) {
                $cover[$line['period_id'].'|'.$ancestor][] = $line;
            }
        }
    }

    public static function dimensionsOverlap(array $a, array $b): bool
    {
        foreach (['branch_id', 'business_unit_id', 'cost_center_id'] as $dimension) {
            if ($a[$dimension] !== null && $b[$dimension] !== null && $a[$dimension] !== $b[$dimension]) {
                return false;
            }
        }

        return true;
    }

    /** The account followed by its ancestors, nearest first. @param array<string,?string> $parents @return list<string> */
    public static function chain(string $accountId, array $parents): array
    {
        $chain = [$accountId];
        $seen = [$accountId => true];
        while (($parent = $parents[$accountId] ?? null) !== null && ! isset($seen[$parent])) {
            $chain[] = $parent;
            $seen[$parent] = true;
            $accountId = $parent;
        }

        return $chain;
    }

    /** @return list<array<string,mixed>> the stored lines of a period as comparable rows */
    private function existing(string $versionId, string $periodId): array
    {
        return BudgetLine::query()->where('budget_version_id', $versionId)->where('accounting_period_id', $periodId)->get()
            ->map(fn (BudgetLine $l) => $this->asRow($l))->all();
    }

    private function asRow(BudgetLine $l): array
    {
        return [
            'id' => $l->id, 'account_id' => $l->account_id, 'period_id' => $l->accounting_period_id, 'amount' => BigDecimal::of((string) $l->amount),
            'branch_id' => $l->branch_id, 'business_unit_id' => $l->business_unit_id, 'cost_center_id' => $l->cost_center_id, 'description' => $l->description,
        ];
    }

    private function row(BudgetVersion $version, array $line, string $actorId, $now): array
    {
        return [
            'id' => (string) Str::uuid7(), 'tenant_id' => $version->tenant_id, 'budget_id' => $version->budget_id, 'budget_version_id' => $version->id,
            'account_id' => $line['account_id'], 'accounting_period_id' => $line['period_id'], 'amount' => Money::str($line['amount']),
            'branch_id' => $line['branch_id'], 'business_unit_id' => $line['business_unit_id'], 'cost_center_id' => $line['cost_center_id'],
            'description' => $line['description'], 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function lineSummary(array $line): array
    {
        return [
            'account_id' => $line['account_id'], 'period_id' => $line['period_id'], 'amount' => Money::str($line['amount']),
            'branch_id' => $line['branch_id'], 'business_unit_id' => $line['business_unit_id'], 'cost_center_id' => $line['cost_center_id'],
        ];
    }

    private function lineOf(BudgetVersion $version, BudgetLine $line): BudgetLine
    {
        $line = BudgetLine::query()->where('budget_version_id', $version->id)->find($line->id);
        abort_if($line === null || ! $this->scope->visible($line), 404);

        return $line;
    }

    private function assertWritable(?string $branchId, ?string $unitId, ?string $creatorId): void
    {
        $this->scope->assertWritable($branchId, $unitId, $creatorId);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function lockDraft(BudgetVersion $version): BudgetVersion
    {
        $version = BudgetVersion::query()->lockForUpdate()->findOrFail($version->id);
        if ($version->status !== BudgetVersion::DRAFT) {
            throw new DomainException("A {$version->status} budget version cannot be edited; revise it as a new version.", 'BUDGET_VERSION_NOT_DRAFT', 409, ['status' => $version->status]);
        }

        return $version;
    }

    private function assertBudgetOpenForWork(BudgetVersion $version): void
    {
        $status = Budget::query()->whereKey($version->budget_id)->value('status');
        if (! in_array($status, [Budget::DRAFT, Budget::ACTIVE], true)) {
            throw new DomainException("The budget is {$status}; its versions can no longer move.", 'BUDGET_NOT_EDITABLE', 409, ['status' => $status]);
        }
    }

    private function defaultRevisionStart(BudgetVersion $current, FiscalYear $year): string
    {
        $today = Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
        $start = max($today, $current->effective_from->copy()->addDay()->toDateString());

        return min($start, $year->end_date->toDateString());
    }

    private function moveBudget(Budget $budget, string $to, User $actor, ?string $reason, callable $apply, ?callable $guard = null): Budget
    {
        return DB::transaction(function () use ($budget, $to, $actor, $reason, $apply, $guard) {
            $budget = Budget::query()->lockForUpdate()->findOrFail($budget->id);
            $from = $budget->status;
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException("A {$from} budget cannot become {$to}.", 'BUDGET_INVALID_TRANSITION', 409, ['from' => $from, 'to' => $to]);
            }
            if ($guard) {
                $guard($budget);
            }
            $apply($budget);
            $budget->status = $to;
            $budget->save();

            $this->workflow->log($budget, $from, $to, $actor->id, $reason);
            $this->audit->record('budget.'.($to === Budget::ACTIVE ? 'activated' : strtolower($to)), Budget::DOCUMENT_TYPE, $budget->id, ['status' => $from], ['status' => $to] + ($reason ? ['reason' => $reason] : []));

            return $this->load($budget);
        });
    }

    private function responsible(?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }
        $member = preg_match('/^[0-9a-f-]{36}$/i', $userId)
            ? TenantUser::query()->where('tenant_id', $this->context->tenantId())->where('user_id', $userId)->where('status', TenantUser::ACTIVE)->exists()
            : false;
        if (! $member) {
            throw new DomainException('The responsible person is not an active member of this tenant.', 'BUDGET_RESPONSIBLE_INVALID', 422, ['field' => 'responsible_user_id']);
        }

        return $userId;
    }

    private function code(string $code): string
    {
        $code = trim($code);
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $code)) {
            throw new DomainException('The budget code is required (letters, digits, dot, dash, underscore; at most 30).', 'BUDGET_CODE_INVALID', 422, ['field' => 'code']);
        }

        return $code;
    }

    private function name(mixed $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            throw new DomainException('The budget name is required.', 'NAME_REQUIRED', 422, ['field' => 'name']);
        }

        return mb_substr($name, 0, 150);
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function summary(Budget $budget): array
    {
        return $budget->only(['code', 'name', 'description', 'fiscal_year_id', 'currency', 'status', 'responsible_user_id']);
    }

    private function versionSummary(BudgetVersion $version): array
    {
        return $version->only(['budget_id', 'version_number', 'label', 'description', 'status', 'effective_from', 'effective_until']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505' && str_contains($e->getMessage(), 'budgets_tenant_id_code_unique')) {
                throw new DomainException('A budget with this code already exists.', 'BUDGET_CODE_TAKEN', 422, ['field' => 'code']);
            }
            throw $e;
        }
    }
}
