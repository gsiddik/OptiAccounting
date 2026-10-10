<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountingProfileService;
use App\Domain\Accounting\Services\ChartOfAccountsService;
use App\Domain\Accounting\Services\CostCenterService;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\JournalWorkflow;
use App\Domain\Accounting\Services\OpeningBalanceService;
use App\Domain\Accounting\Services\ReversalService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Support\TenantContext;
use Carbon\CarbonInterface;

/**
 * Demo books for one tenant (called by DemoSeeder; never by DatabaseSeeder): profile, fiscal year of the current calendar
 * year, chart of accounts, an opening balance, posted journals through the real approval workflow, a few pending ones,
 * one reversal and closed / soft-closed early periods. The accounting events (posting rules) are published by DemoOperationalSeeder. Everything goes through
 * the same services as production code, so the demo data obeys every accounting invariant.
 */
class DemoAccountingSeeder
{
    private TenantContext $context;

    private JournalService $journals;

    private JournalWorkflow $workflow;

    /** @var array<string,string> */
    private array $accounts = [];

    /** @var array<string,string> */
    private array $branches = [];

    public function seed(Tenant $tenant, User $accountant, User $manager): void
    {
        $this->context = app(TenantContext::class);
        $this->context->runAs($tenant->id, function () use ($accountant, $manager) {
            $this->journals = app(JournalService::class);
            $this->workflow = app(JournalWorkflow::class);

            $today = now(config('app.timezone'));
            $year = $today->year;

            $this->as($manager, function () use ($year) {
                app(AccountingProfileService::class)->save(['framework' => 'SAK_EP', 'functional_currency' => 'IDR', 'currency_scale' => 2]);
                $fiscal = app(FiscalCalendarService::class);
                $fiscal->openFiscalYear($fiscal->createFiscalYear(['code' => "FY{$year}", 'name' => "Tahun Fiskal {$year}", 'start_date' => "{$year}-01-01"]));
                app(ChartOfAccountsService::class)->applyTemplate('UMUM_ID');
                app(CostCenterService::class)->create(['code' => 'MKT', 'name' => 'Pemasaran']);
                app(CostCenterService::class)->create(['code' => 'OPS', 'name' => 'Operasional']);
                app(AccountingProfileService::class)->activate();
            });

            $this->accounts = Account::query()->pluck('id', 'code')->all();
            $this->branches = Branch::query()->pluck('id', 'code')->all();

            $this->as($manager, fn () => $this->openingBalance($year, $manager));
            $this->monthlyJournals($year, $today, $accountant, $manager);
            $this->pendingJournals($today, $accountant, $manager);
            $this->as($manager, fn () => $this->closeEarlyPeriods($year, $today->month));
        });
    }

    private function openingBalance(int $year, User $manager): void
    {
        $line = fn (string $code, string $side, string $amount) => ['account_id' => $this->accounts[$code], $side => $amount, 'branch_id' => $this->branches['JKT'] ?? null];
        $openings = app(OpeningBalanceService::class);
        $openings->save([
            'cutover_date' => "{$year}-01-01", 'reference' => 'SALDO-AWAL', 'description' => "Saldo awal per 1 Januari {$year}",
            'lines' => [
                $line('1110', 'debit', '250000000'), $line('1120', 'debit', '500000000'), $line('1130', 'debit', '120000000'),
                $line('2110', 'credit', '80000000'), $line('3100', 'credit', '790000000'),
            ],
        ], $manager);
        $openings->post($manager);
    }

    private function monthlyJournals(int $year, $today, User $accountant, User $manager): void
    {
        $reversible = null;
        for ($month = 1; $month <= $today->month; $month++) {
            $branch = $this->branches[$month % 2 ? 'JKT' : 'SBY'] ?? null;
            $day = fn (int $d) => sprintf('%04d-%02d-%02d', $year, $month, $d);
            $plan = [
                [$day(5), 'Penjualan tunai '.$this->monthName($month), "INV-{$month}01", [
                    ['1110', 'debit', (string) (45000000 + $month * 2500000)], ['4100', 'credit', (string) (45000000 + $month * 2500000)],
                ], $branch],
                [$day(1), 'Sewa kantor '.$this->monthName($month), "SEWA-{$month}", [['6200', 'debit', '12000000'], ['1120', 'credit', '12000000']], $this->branches['JKT'] ?? null],
                [$day(25), 'Gaji karyawan '.$this->monthName($month), "GAJI-{$month}", [
                    ['6100', 'debit', '18000000', $this->branches['JKT'] ?? null], ['6100', 'debit', '10000000', $this->branches['SBY'] ?? null], ['1120', 'credit', '28000000', $this->branches['JKT'] ?? null],
                ], null],
            ];

            foreach ($plan as [$date, $description, $reference, $lines, $branchId]) {
                if ($date > $today->toDateString()) {
                    continue;
                }
                $journal = $this->posted($date, $description, $reference, $lines, $branchId, $accountant, $manager);
                if ($month === 3 && str_starts_with($reference, 'SEWA')) {
                    $reversible = $journal;
                }
            }
        }

        if ($reversible) { // a correction: the March rent was booked twice by mistake, so reverse one posting
            $this->as($manager, fn () => app(ReversalService::class)->reverse($reversible, $manager, 'Salah input: sewa tercatat dua kali', $reversible->posting_date->toDateString()));
        }
    }

    /** What an accountant's desk looks like today: two drafts, one waiting for approval, one approved and waiting to post. */
    private function pendingJournals(CarbonInterface $today, User $accountant, User $manager): void
    {
        $date = $today->toDateString();
        $make = fn (string $description, string $reference, array $lines) => $this->as($accountant, fn () => $this->journals->createManual([
            'document_date' => $date, 'posting_date' => $date, 'description' => $description, 'reference' => $reference,
            'lines' => array_map(fn ($l) => ['account_id' => $this->accounts[$l[0]], $l[1] => $l[2], 'branch_id' => $this->branches['JKT'] ?? null], $lines),
        ], $accountant));

        $make('Pembelian alat tulis kantor', 'ATK-001', [['6900', 'debit', '1500000'], ['1110', 'credit', '1500000']]);
        $make('Perawatan kendaraan operasional', 'SERVIS-001', [['6400', 'debit', '3250000'], ['1120', 'credit', '3250000']]);
        $submitted = $make('Pembelian BBM armada', 'BBM-001', [['6500', 'debit', '8400000'], ['1120', 'credit', '8400000']]);
        $this->as($accountant, fn () => $this->workflow->submit($submitted, $accountant));
        $approved = $make('Perbaikan peralatan gudang', 'ALAT-001', [['1230', 'debit', '5600000'], ['1110', 'credit', '5600000']]);
        $this->as($accountant, fn () => $this->workflow->submit($approved, $accountant));
        $this->as($manager, fn () => $this->workflow->approve($approved, $manager));
    }

    /** January is hard-closed and February soft-closed once there are later months to work in. */
    private function closeEarlyPeriods(int $year, int $currentMonth): void
    {
        if ($currentMonth < 3) {
            return;
        }
        $fiscal = app(FiscalCalendarService::class);
        $period = fn (int $m) => AccountingPeriod::query()->where('code', sprintf('%04d-%02d', $year, $m))->firstOrFail();
        $fiscal->transitionPeriod($period(1), AccountingPeriod::SOFT_CLOSED);
        $fiscal->transitionPeriod($period(1), AccountingPeriod::CLOSED);
        $fiscal->transitionPeriod($period(2), AccountingPeriod::SOFT_CLOSED);
    }

    /** A MANUAL journal prepared by the accountant, approved and posted by the manager. */
    private function posted(string $date, string $description, string $reference, array $lines, ?string $defaultBranch, User $accountant, User $manager): JournalEntry
    {
        $journal = $this->as($accountant, fn () => $this->journals->createManual([
            'document_date' => $date, 'posting_date' => $date, 'description' => $description, 'reference' => $reference,
            'lines' => array_map(fn ($l) => ['account_id' => $this->accounts[$l[0]], $l[1] => $l[2], 'branch_id' => $l[3] ?? $defaultBranch], $lines),
        ], $accountant));
        $this->as($accountant, fn () => $this->workflow->submit($journal, $accountant));
        $this->as($manager, fn () => $this->workflow->approve($journal, $manager));

        return $this->as($manager, fn () => $this->workflow->post($journal, $manager));
    }

    private function as(User $user, callable $work): mixed
    {
        $previous = $this->context->user();
        $this->context->setUser($user);
        try {
            return $work();
        } finally {
            $this->context->setUser($previous);
        }
    }

    private function monthName(int $month): string
    {
        return ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$month - 1];
    }
}
