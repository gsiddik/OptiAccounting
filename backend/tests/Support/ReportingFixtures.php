<?php

namespace Tests\Support;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Accounting\Support\SourceTypes;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test helpers for OA5 reporting (also meant for the closing and year-end tests): a deterministic two-year ledger on the standard chart of
 * accounts, helpers to post journals between accounts by code, and the report definitions published from the tenant's own chart. Combine with
 * AccountingFixtures and Fixtures (PayablesFixtures for memberToken). Helpers that call the API (postOpening, standardLedger, publishedDefinitions,
 * statement) act as the already-authenticated client; standardLedger and publishedDefinitions sign in a member of the tenant they are given.
 *
 *   reportingTenant      tenant with FY2025 and FY2026 open        book             a balanced two-line POSTED journal by account codes (+ dimensions)
 *   standardLedger       the ledger below                          closingJournal   a year-end closing journal as the closing area posts it
 *   publishedDefinitions default definitions, published            twoBranches      two branches [north, south]
 *   branchToken          a member whose data scope is one branch   statement        GET reports/<path> as JSON (statements, comparative, drilldown, ...)
 *   linesByCode / accountAmount   read a statement                 csvRows / auditCount   read an export / count audit rows
 *
 * The standard ledger (IDR, FY2025 and FY2026 both open, opening balances at 2025-01-01, no year-end closing yet):
 *
 *   2025-01-01 opening   cash 5,000,000  bank 3,000,000  equipment 2,000,000  | paid-in capital 8,000,000  bank loan 2,000,000
 *   2025-03-15 sale      cash +1,000,000 / sales            2025-06-10 rent   bank -200,000 / rent expense
 *   2025-09-01 loan      bank +1,000,000 / bank loan
 *   2026-01-15 sale      cash +2,000,000 / sales            2026-01-31 salary cash -300,000 / salary expense
 *   2026-02-01 rent      bank -200,000 / rent expense       2026-02-20 sale   bank +800,000 / sales
 *   2026-03-05 equipment cash -500,000 / equipment          2026-03-31 cost of sales 400,000 / other payables
 *   2026-04-05 sale      cash +600,000 / sales
 *
 * Net result: FY2025 800,000; FY2026 to 2026-03-31 1,900,000, to 2026-04-30 2,500,000.
 * At 2026-03-31: assets 14,100,000 = liabilities 3,400,000 + equity 8,000,000 + results 2,700,000.
 */
trait ReportingFixtures
{
    protected const RP = '/api/v1/app/accounting';

    /** An accounting tenant with FY2025 and FY2026 both open (the chart, role mappings and an active profile come from AccountingFixtures; `$profile` overrides the profile settings). */
    protected function reportingTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        $tenant = $this->accountingTenant($code, '2025-01-01', profile: $profile);
        $this->inTenant($tenant, function () {
            $calendar = app(FiscalCalendarService::class);
            $calendar->openFiscalYear($calendar->createFiscalYear(['code' => 'FY2026', 'name' => 'Tahun 2026', 'start_date' => '2026-01-01']));
        });

        return $tenant;
    }

    /** A balanced two-line posted journal between two accounts (codes), debit first, optionally on a branch / business unit / cost center. Returns the posted journal. */
    protected function book(Tenant $tenant, string $date, string $debit, string $credit, string $amount, array $dimensions = [], array $override = []): JournalEntry
    {
        return $this->postedJournal($tenant, $override + [
            'document_date' => $date, 'posting_date' => $date, 'lines' => $this->lines($tenant, $amount, $debit, $credit, $dimensions),
        ]);
    }

    /** @return array{0:string,1:string} the ids of two branches (north, south) */
    protected function twoBranches(Tenant $tenant): array
    {
        $org = app(OrganizationService::class);

        return $this->inTenant($tenant, fn () => [
            $org->createBranch($tenant->id, ['code' => 'N', 'name' => 'North'])->id, $org->createBranch($tenant->id, ['code' => 'S', 'name' => 'South'])->id,
        ]);
    }

    /** A bearer token for a member holding exactly $permissions whose data scope is one branch (or OWN / TENANT). */
    protected function branchToken(Tenant $tenant, array $permissions, string $type = 'BRANCH', ?string $branchId = null): string
    {
        [$user, $membership] = $this->member($tenant, $permissions);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => $type,
            'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->tenantToken($user, $tenant);
    }

    /** The opening balances of the standard ledger, posted through the API as the signed-in client. */
    protected function postOpening(Tenant $tenant, string $cutover = '2025-01-01'): void
    {
        $id = fn (string $code) => $this->account($tenant, $code)->id;
        $this->putJson(self::RP.'/opening-balance', ['cutover_date' => $cutover, 'lines' => [
            ['account_id' => $id('1110'), 'debit' => '5000000'], ['account_id' => $id('1120'), 'debit' => '3000000'], ['account_id' => $id('1230'), 'debit' => '2000000'],
            ['account_id' => $id('3100'), 'credit' => '8000000'], ['account_id' => $id('2210'), 'credit' => '2000000'],
        ]])->assertOk();
        $this->postJson(self::RP.'/opening-balance/post')->assertOk();
    }

    /** The deterministic two-year ledger described above. */
    protected function standardLedger(Tenant $tenant): void
    {
        $this->signedIn($tenant);
        $this->postOpening($tenant);

        $this->book($tenant, '2025-03-15', '1110', '4100', '1000000');
        $this->book($tenant, '2025-06-10', '6200', '1120', '200000');
        $this->book($tenant, '2025-09-01', '1120', '2210', '1000000');

        $this->book($tenant, '2026-01-15', '1110', '4100', '2000000');
        $this->book($tenant, '2026-01-31', '6100', '1110', '300000');
        $this->book($tenant, '2026-02-01', '6200', '1120', '200000');
        $this->book($tenant, '2026-02-20', '1120', '4100', '800000');
        $this->book($tenant, '2026-03-05', '1230', '1110', '500000');
        $this->book($tenant, '2026-03-31', '5100', '2140', '400000');
        $this->book($tenant, '2026-04-05', '1110', '4100', '600000');
    }

    /**
     * A year-end closing journal as the closing area posts it: every temporary account of the year brought to zero against retained earnings.
     * Posted as a SYSTEM journal carrying the closing marker; the amounts are the given balances per account.
     *
     * @param  array<string,string>  $nets  P&L account code => the credit-minus-debit balance to clear (a revenue 1,000 is "1000", an expense 300 is "-300")
     */
    protected function closingJournal(Tenant $tenant, string $date, array $nets, string $sourceId = 'test-closing:1'): JournalEntry
    {
        $lines = [];
        $total = BigDecimal::zero();
        foreach ($nets as $code => $net) {
            $value = BigDecimal::of($net);
            $lines[] = $value->isPositive()
                ? ['account_id' => $this->account($tenant, $code)->id, 'debit' => (string) $value, 'description' => 'Tutup '.$code]
                : ['account_id' => $this->account($tenant, $code)->id, 'credit' => (string) $value->abs(), 'description' => 'Tutup '.$code];
            $total = $total->plus($value);
        }
        // The result goes to retained earnings: a profit (positive total) is credited, a loss debited.
        $lines[] = $total->isPositive()
            ? ['account_id' => $this->account($tenant, '3200')->id, 'credit' => (string) $total, 'description' => 'Laba ditahan']
            : ['account_id' => $this->account($tenant, '3200')->id, 'debit' => (string) $total->abs(), 'description' => 'Laba ditahan'];

        return $this->postedJournal($tenant, ['document_date' => $date, 'posting_date' => $date, 'description' => 'Penutupan akhir tahun', 'reference' => null,
            'source_type' => SourceTypes::YEAR_END_CLOSING, 'source_id' => $sourceId, 'lines' => $lines]);
    }

    /** Defaults built from the tenant's chart and published (first versions: effective from the beginning). Returns statement type => definition. @return array<string,array<string,mixed>> */
    protected function publishedDefinitions(Tenant $tenant, ?string $from = null): array
    {
        $this->signedIn($tenant);
        $created = $this->postJson(self::RP.'/report-definitions/defaults')->assertSuccessful()->json('created');
        $out = [];
        foreach ($created as $definition) {
            $out[$definition['statement_type']] = $this->postJson(self::RP."/report-definitions/{$definition['id']}/publish", $from ? ['effective_from' => $from] : [])->assertOk()->json();
        }

        return $out;
    }

    /** @return array<string,mixed> */
    protected function statement(string $path, array $query = []): array
    {
        return $this->getJson(self::RP."/reports/{$path}?".http_build_query($query))->assertOk()->json();
    }

    /** The lines of a statement keyed by line code. @return array<string,array<string,mixed>> */
    protected function linesByCode(array $statement): array
    {
        return collect($statement['lines'])->keyBy('code')->all();
    }

    /** The amount of the statement line that maps the given account code (ACC_<code> in the generated definitions). */
    protected function accountAmount(array $statement, string $accountCode): ?string
    {
        foreach ($statement['lines'] as $line) {
            foreach ($line['accounts'] ?? [] as $account) {
                if ($account['code'] === $accountCode) {
                    return $account['amount'];
                }
            }
        }

        return null;
    }

    /** Streamed CSV rows of the last response (header included), without the byte-order mark. @return list<list<string>> */
    protected function csvRows(string $body): array
    {
        $body = ltrim($body, "\xEF\xBB\xBF");
        $rows = [];
        foreach (explode("\n", rtrim($body)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }

    protected function auditCount(string $action): int
    {
        return DB::table('audit_logs')->where('action', $action)->count();
    }
}
