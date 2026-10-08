<?php

namespace Tests\Feature\Accounting;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1 §62: the read endpoints run the same number of queries for 4 rows as for 40 (no N+1). */
class QueryBudgetTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const A = '/api/v1/app/accounting';

    private Tenant $tenant;

    private string $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->accountingTenant();
        $org = app(OrganizationService::class);
        $this->branch = $this->inTenant($this->tenant, fn () => $org->createBranch($this->tenant->id, ['code' => 'JKT', 'name' => 'Jakarta'])->id);
        $this->signedIn($this->tenant);
    }

    private function grow(int $journals, int $linePairs = 1): void
    {
        for ($i = 0; $i < $journals; $i++) {
            $lines = [];
            for ($p = 0; $p < $linePairs; $p++) {
                $lines = array_merge($lines, $this->lines($this->tenant, (string) (100 + $p), dimensions: ['branch_id' => $this->branch]));
            }
            $this->postedJournal($this->tenant, ['lines' => $lines]);
            $this->postJson(self::A.'/journals', $this->journalBody($this->tenant, ['lines' => $lines]))->assertCreated();
        }
    }

    private function queries(string $uri): int
    {
        $this->getJson($uri)->assertOk(); // warm the permission and entitlement caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<string,string> */
    private function endpoints(string $journalId): array
    {
        $cash = $this->account($this->tenant, '1110')->id;

        return [
            'journal list' => self::A.'/journals?per_page=50',
            'journal detail' => self::A."/journals/{$journalId}",
            'account list' => self::A.'/accounts',
            'general ledger' => self::A."/general-ledger?account_id={$cash}&from=2026-01-01&to=2026-12-31&per_page=100",
            'trial balance' => self::A.'/trial-balance?from=2026-01-01&to=2026-12-31',
            'dashboard' => self::A.'/dashboard',
            'dimension catalog' => self::A.'/dimensions',
        ];
    }

    public function test_read_endpoints_do_not_run_a_query_per_row(): void
    {
        $this->grow(2);
        $small = $this->postJson(self::A.'/journals', $this->journalBody($this->tenant, ['lines' => $this->lines($this->tenant, '5', dimensions: ['branch_id' => $this->branch])]))->assertCreated()->json('id'); // same relations as the big one: an eager load with no keys is skipped
        $before = array_map(fn ($uri) => $this->queries($uri), $this->endpoints($small));

        $this->grow(18, linePairs: 3);
        $big = $this->postJson(self::A.'/journals', $this->journalBody($this->tenant, ['lines' => array_merge(...array_map(fn () => $this->lines($this->tenant, '5', dimensions: ['branch_id' => $this->branch]), range(1, 15)))]))->assertCreated()->json('id');
        $after = array_map(fn ($uri) => $this->queries($uri), $this->endpoints($big));

        $this->assertSame($before, $after, 'a read endpoint grew with the number of rows: '.json_encode(['before' => $before, 'after' => $after]));
        foreach ($after as $label => $count) {
            $this->assertLessThanOrEqual(25, $count, "{$label} runs {$count} queries");
        }
    }
}
