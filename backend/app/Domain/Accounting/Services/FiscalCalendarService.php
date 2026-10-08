<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Fiscal years (any start month, 1-18 months) and their monthly accounting periods, with the period lifecycle. */
class FiscalCalendarService
{
    private const MONTHS_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContext $context,
        private readonly PeriodGuard $guard,
    ) {}

    /** @param array{code:string,name:string,start_date:string,months?:int} $data */
    public function createFiscalYear(array $data): FiscalYear
    {
        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $months = (int) ($data['months'] ?? 12);
        if ($start->day !== 1) {
            throw new DomainException('A fiscal year starts on the first day of a month.', 'FISCAL_YEAR_INVALID_START', 422);
        }
        if ($months < 1 || $months > 18) {
            throw new DomainException('A fiscal year has between 1 and 18 monthly periods.', 'FISCAL_YEAR_INVALID_LENGTH', 422);
        }
        $end = $start->addMonthsNoOverflow($months)->subDay();

        try {
            return DB::transaction(function () use ($data, $start, $end, $months) {
                $year = new FiscalYear(['code' => $data['code'], 'name' => $data['name'], 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]);
                $year->status = FiscalYear::DRAFT;
                $year->save();

                for ($i = 0; $i < $months; $i++) {
                    $from = $start->addMonthsNoOverflow($i);
                    $period = new AccountingPeriod;
                    $period->fiscal_year_id = $year->id;
                    $period->number = $i + 1;
                    $period->code = $from->format('Y-m');
                    $period->name = self::MONTHS_ID[$from->month - 1].' '.$from->year;
                    $period->start_date = $from->toDateString();
                    $period->end_date = $from->endOfMonth()->toDateString();
                    $period->status = AccountingPeriod::FUTURE;
                    $period->save();
                }

                $this->audit->record('accounting.fiscal_year.created', 'fiscal_year', $year->id, null, $year->only(['code', 'name', 'start_date', 'end_date']) + ['periods' => $months]);

                return $year->load('periods');
            });
        } catch (QueryException $e) {
            $this->translate($e);
        }
    }

    /** DRAFT -> OPEN. With $openPeriods every period of the year becomes OPEN (otherwise they are opened one by one). */
    public function openFiscalYear(FiscalYear $year, bool $openPeriods = true): FiscalYear
    {
        return DB::transaction(function () use ($year, $openPeriods) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);
            if ($year->status !== FiscalYear::DRAFT) {
                throw new DomainException('Only a draft fiscal year can be opened.', 'FISCAL_YEAR_NOT_DRAFT', 409);
            }
            $year->status = FiscalYear::OPEN;
            $year->save();
            if ($openPeriods) {
                $year->periods()->where('status', AccountingPeriod::FUTURE)->update(['status' => AccountingPeriod::OPEN, 'updated_at' => now()]);
            }
            $this->audit->record('accounting.fiscal_year.opened', 'fiscal_year', $year->id, ['status' => FiscalYear::DRAFT], ['status' => FiscalYear::OPEN, 'periods_opened' => $openPeriods]);

            return $year->load('periods');
        });
    }

    /** OPEN -> CLOSED once every period is closed. Year-end closing entries belong to OA5. */
    public function closeFiscalYear(FiscalYear $year): FiscalYear
    {
        return DB::transaction(function () use ($year) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);
            if ($year->status !== FiscalYear::OPEN) {
                throw new DomainException('Only an open fiscal year can be closed.', 'FISCAL_YEAR_NOT_OPEN', 409);
            }
            if ($year->periods()->where('status', '<>', AccountingPeriod::CLOSED)->exists()) {
                throw new DomainException('Close every period of the fiscal year first.', 'FISCAL_YEAR_HAS_OPEN_PERIODS', 409);
            }
            $year->status = FiscalYear::CLOSED;
            $year->save();
            $this->audit->record('accounting.fiscal_year.closed', 'fiscal_year', $year->id, ['status' => FiscalYear::OPEN], ['status' => FiscalYear::CLOSED]);

            return $year->load('periods');
        });
    }

    /** A draft fiscal year has no history, so it may be removed; anything else is kept. */
    public function deleteFiscalYear(FiscalYear $year): void
    {
        DB::transaction(function () use ($year) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);
            if ($year->status !== FiscalYear::DRAFT) {
                throw new DomainException('Only a draft fiscal year can be deleted.', 'FISCAL_YEAR_NOT_DRAFT', 409);
            }
            $year->delete();
            $this->audit->record('accounting.fiscal_year.deleted', 'fiscal_year', $year->id, $year->only(['code', 'name']), null);
        });
    }

    public function transitionPeriod(AccountingPeriod $period, string $to): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $to) {
            // FOR UPDATE: waits for postings that hold the period FOR SHARE, and blocks new ones until commit.
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $from = $period->status;

            if (! in_array($to, AccountingPeriod::TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException("A {$from} period cannot become {$to}.", 'PERIOD_INVALID_TRANSITION', 409, ['from' => $from, 'to' => $to]);
            }

            if ($to === AccountingPeriod::OPEN && FiscalYear::query()->find($period->fiscal_year_id)?->status !== FiscalYear::OPEN) {
                throw new DomainException('Open the fiscal year before opening its periods.', 'FISCAL_YEAR_NOT_OPEN', 409);
            }

            if ($to === AccountingPeriod::CLOSED) {
                $pending = DB::table('journal_entries')->where('tenant_id', $period->tenant_id)->whereIn('status', ['SUBMITTED', 'APPROVED'])
                    ->whereBetween('posting_date', [$period->start_date->toDateString(), $period->end_date->toDateString()])->count();
                if ($pending > 0) {
                    throw new DomainException('Post or cancel the submitted and approved journals of this period first.', 'PERIOD_HAS_PENDING_JOURNALS', 409, ['pending' => $pending]);
                }
                $period->closed_at = now();
                $period->closed_by = $this->context->user()?->id;
            }

            $period->status = $to;
            $period->save();
            $this->audit->record('accounting.period.status_changed', 'accounting_period', $period->id, ['status' => $from, 'code' => $period->code], ['status' => $to, 'code' => $period->code]);

            return $period;
        });
    }

    /** @return array<string,mixed>|null the period that covers a date (read-only; posting uses PeriodGuard) */
    public function periodFor(string $date): ?AccountingPeriod
    {
        return AccountingPeriod::query()->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
    }

    private function translate(QueryException $e): never
    {
        $state = $e->errorInfo[0] ?? '';
        $message = (string) ($e->errorInfo[2] ?? '');
        if ($state === '23P01') {
            throw new DomainException('This fiscal year overlaps an existing fiscal year.', 'FISCAL_YEAR_OVERLAP', 422);
        }
        if ($state === '23505') {
            throw new DomainException('A fiscal year or period with this code already exists.', 'FISCAL_YEAR_CODE_TAKEN', 422);
        }
        throw $e;
    }
}
