<?php

namespace Tests\Feature\Reporting;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Reporting\Services\ReportDefinitionResolver;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/** OA5 batch B: report definitions - authoring, validation findings, defaults from the chart, versioning, mapping safety and database immutability. */
class ReportDefinitionTest extends TestCase
{
    use AccountingFixtures, Fixtures, ReportingFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant();
        $this->signedIn($this->tenant);
    }

    private function create(string $type = 'PROFIT_LOSS', string $name = 'Laba rugi uji'): TestResponse
    {
        return $this->postJson(self::RP.'/report-definitions', ['statement_type' => $type, 'name' => $name]);
    }

    /** A line in the shape of the API. */
    private function ln(string $code, string $type, ?string $parent = null, array $extra = []): array
    {
        return ['code' => $code, 'label' => $extra['label'] ?? $code, 'line_type' => $type, 'parent_code' => $parent] + $extra;
    }

    /** A small profit and loss: revenue and expense sections over one account each, and the result. */
    private function profitLossLines(): array
    {
        return [
            $this->ln('REV', 'SUBTOTAL'),
            $this->ln('REV_SALES', 'ACCOUNT', 'REV', ['balance_side' => 'CREDIT']),
            $this->ln('EXP', 'SUBTOTAL'),
            $this->ln('EXP_RENT', 'ACCOUNT', 'EXP', ['balance_side' => 'DEBIT']),
            $this->ln('RESULT', 'CALCULATED_TOTAL', null, ['operands' => [['line' => 'REV', 'sign' => '+'], ['line' => 'EXP', 'sign' => '-']], 'emphasis' => true]),
        ];
    }

    private function map(string $line, string $accountCode, bool $descendants = false): array
    {
        return ['line_code' => $line, 'account_id' => $this->account($this->tenant, $accountCode)->id, 'include_descendants' => $descendants];
    }

    private function codes(array $findings): array
    {
        return array_values(array_unique(array_column($findings, 'code')));
    }

    /** A draft profit and loss with the small layout and its mapping, ready to publish. @return string the definition id */
    private function readyDraft(string $type = 'PROFIT_LOSS'): string
    {
        $id = $this->create($type)->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => $this->profitLossLines()])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [$this->map('REV_SALES', '4100'), $this->map('EXP_RENT', '6200')]])->assertOk();

        return $id;
    }

    private function validateIt(string $id): array
    {
        return $this->postJson(self::RP."/report-definitions/{$id}/validate")->assertOk()->json();
    }

    // ------------------------------------------------------------------------------------------ defaults from the chart

    public function test_the_defaults_build_valid_drafts_for_all_three_statements_from_the_tenants_own_chart(): void
    {
        $response = $this->postJson(self::RP.'/report-definitions/defaults')->assertCreated();

        $created = collect($response->json('created'))->keyBy('statement_type');
        $this->assertEqualsCanonicalizing(['BALANCE_SHEET', 'PROFIT_LOSS', 'CASH_FLOW'], $created->keys()->all());
        $this->assertSame([], $response->json('skipped'));
        foreach ($created as $type => $definition) {
            $this->assertSame('DRAFT', $definition['status'], $type);
            $this->assertSame(1, $definition['version'], $type);
            $this->assertTrue($definition['validation']['valid'], $type.': '.json_encode($definition['validation']['errors']));
        }
        $this->assertSame(3, $this->auditCount('accounting.report_definition.created'));

        // Every active posting account of the statement's types sits on exactly one line (the database also forbids two lines for an account).
        $bs = $this->getJson(self::RP.'/report-definitions/'.$created['BALANCE_SHEET']['id'])->assertOk()->json();
        $mapped = collect($bs['lines'])->flatMap(fn ($l) => collect($l['mappings'])->pluck('code'))->all();
        $expected = DB::table('accounts')->where('tenant_id', $this->tenant->id)->where('is_postable', true)->whereIn('account_type', ['ASSET', 'LIABILITY', 'EQUITY'])->orderBy('code')->pluck('code')->all();
        sort($mapped);
        $this->assertSame($expected, $mapped);
        $this->assertContains('CURRENT_RESULT', array_column($bs['lines'], 'code'));
        $this->assertContains('TOT_LIABILITIES_EQUITY', array_column($bs['lines'], 'code'));
    }

    public function test_the_default_cash_flow_classifies_accounts_from_their_roles_and_never_lists_cash_itself(): void
    {
        $this->postJson(self::RP.'/accounts', ['code' => '1900', 'name' => 'Aset lain-lain', 'account_type' => 'ASSET', 'is_postable' => true])->assertCreated();
        $created = collect($this->postJson(self::RP.'/report-definitions/defaults', ['statement_types' => ['CASH_FLOW']])->assertCreated()->json('created'))->first();
        $detail = $this->getJson(self::RP."/report-definitions/{$created['id']}")->assertOk()->json();

        $category = [];
        foreach ($detail['lines'] as $line) {
            foreach ($line['mappings'] as $mapping) {
                $category[$mapping['code']] = $line['cash_flow_category'];
            }
        }
        $this->assertArrayNotHasKey('1110', $category, 'cash is what the statement explains');
        $this->assertArrayNotHasKey('1120', $category);
        $this->assertSame('OPERATING', $category['1130']); // receivable role
        $this->assertSame('OPERATING', $category['1140']); // inventory role
        $this->assertSame('INVESTING', $category['1230']); // fixed asset role
        $this->assertSame('NON_CASH', $category['1290']);  // accumulated depreciation role
        $this->assertSame('OPERATING', $category['2140']); // no role of its own: follows the payables in its group
        $this->assertSame('FINANCING', $category['2210']); // long-term liability
        $this->assertSame('FINANCING', $category['3100']); // equity
        $this->assertSame('OPERATING', $category['1160']); // no role of its own: follows the receivables and inventory of its group
        $this->assertSame('INVESTING', $category['1210']); // follows the fixed asset of its group
        $this->assertSame('UNCLASSIFIED', $category['1900']); // nothing to derive it from: left visible for the owner

        $systemKeys = array_values(array_filter(array_column($detail['lines'], 'system_key')));
        $this->assertEqualsCanonicalizing(['NET_RESULT', 'CASH_OPENING', 'CASH_CLOSING', 'UNCLASSIFIED_CASH_FLOW'], $systemKeys);
    }

    public function test_the_defaults_never_create_a_second_draft_and_leave_published_definitions_alone(): void
    {
        $this->postJson(self::RP.'/report-definitions/defaults')->assertCreated();

        $again = $this->postJson(self::RP.'/report-definitions/defaults')->assertOk();
        $this->assertSame([], $again->json('created'));
        $this->assertEqualsCanonicalizing(['REPORT_DRAFT_EXISTS'], array_unique(array_column($again->json('skipped'), 'code')));
        $this->assertSame(3, DB::table('report_definitions')->where('tenant_id', $this->tenant->id)->count());

        $drafts = $this->getJson(self::RP.'/report-definitions?status=DRAFT')->assertOk()->json('data');
        foreach ($drafts as $draft) {
            $this->postJson(self::RP."/report-definitions/{$draft['id']}/publish")->assertOk();
        }
        $published = $this->postJson(self::RP.'/report-definitions/defaults')->assertOk();
        $this->assertSame([], $published->json('created'));
        $this->assertEqualsCanonicalizing(['REPORT_DEFINITION_EXISTS'], array_unique(array_column($published->json('skipped'), 'code')));
        $this->assertSame(3, DB::table('report_definitions')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_defaults_can_be_limited_to_some_statements_and_a_chart_without_a_statement_type_skips_it(): void
    {
        $one = $this->postJson(self::RP.'/report-definitions/defaults', ['statement_types' => ['PROFIT_LOSS']])->assertCreated();
        $this->assertSame(['PROFIT_LOSS'], array_column($one->json('created'), 'statement_type'));

        $this->postJson(self::RP.'/report-definitions/defaults', ['statement_types' => ['TRIAL']])->assertUnprocessable();

        $bare = $this->tenant('bare');
        $this->signedIn($bare);
        $none = $this->postJson(self::RP.'/report-definitions/defaults')->assertOk();
        $this->assertSame([], $none->json('created'));
        $this->assertSame(['NO_ACCOUNTS'], array_values(array_unique(array_column($none->json('skipped'), 'code'))));
    }

    // ------------------------------------------------------------------------------------------ lifecycle and versioning

    public function test_a_definition_is_authored_validated_published_and_then_immutable(): void
    {
        $id = $this->create()->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('version', 1)->assertJsonPath('code', 'PROFIT_LOSS')->json('id');
        $this->create()->assertConflict()->assertJsonPath('code', 'REPORT_DRAFT_EXISTS');

        $this->assertContains('DEFINITION_EMPTY', $this->codes($this->validateIt($id)['errors']));
        $this->postJson(self::RP."/report-definitions/{$id}/publish")->assertUnprocessable()->assertJsonPath('code', 'REPORT_DEFINITION_INVALID');

        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => $this->profitLossLines()])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [$this->map('REV_SALES', '4100'), $this->map('EXP_RENT', '6200')]])->assertOk();

        $detail = $this->getJson(self::RP."/report-definitions/{$id}")->assertOk()->json();
        $this->assertSame(['REV', 'REV_SALES', 'EXP', 'EXP_RENT', 'RESULT'], array_column($detail['lines'], 'code'));
        $this->assertSame([0, 1, 0, 1, 0], array_column($detail['lines'], 'depth'));
        $this->assertSame('REV', $detail['lines'][1]['parent_code']);
        $this->assertSame('4100', $detail['lines'][1]['mappings'][0]['code']);
        $this->assertTrue($this->validateIt($id)['valid']);

        $published = $this->postJson(self::RP."/report-definitions/{$id}/publish")->assertOk()->assertJsonPath('status', 'ACTIVE')->json();
        $this->assertNull($published['effective_from'], 'the first version applies from the beginning');
        $this->assertNull($published['effective_to']);
        $this->assertNotNull($published['published_at']);

        // A published definition does not change by any route.
        $line = $detail['lines'][1]['id'];
        $this->patchJson(self::RP."/report-definitions/{$id}", ['name' => 'Lain'])->assertConflict()->assertJsonPath('code', 'REPORT_DEFINITION_NOT_DRAFT');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => $this->profitLossLines()])->assertConflict()->assertJsonPath('code', 'REPORT_DEFINITION_NOT_DRAFT');
        $this->postJson(self::RP."/report-definitions/{$id}/lines", $this->ln('EXTRA', 'HEADER'))->assertConflict();
        $this->patchJson(self::RP."/report-definitions/{$id}/lines/{$line}", ['label' => 'x'])->assertConflict();
        $this->deleteJson(self::RP."/report-definitions/{$id}/lines/{$line}")->assertConflict();
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => []])->assertConflict();
        $this->deleteJson(self::RP."/report-definitions/{$id}")->assertConflict();
        $this->postJson(self::RP."/report-definitions/{$id}/publish")->assertConflict();

        $this->assertSame(1, $this->auditCount('accounting.report_definition.created'));
        $this->assertSame(1, $this->auditCount('accounting.report_definition.updated'));
        $this->assertSame(1, $this->auditCount('accounting.report_definition.published'));
        $this->assertSame(0, $this->auditCount('accounting.report_definition.archived'));
        $this->assertSame(1, $this->auditCount('accounting.report_mapping.changed'));
    }

    public function test_a_new_version_copies_the_layout_and_publishing_it_archives_the_previous_one_the_day_before(): void
    {
        $v1 = $this->readyDraft();
        $this->postJson(self::RP."/report-definitions/{$v1}/publish")->assertOk();

        $draft = $this->postJson(self::RP."/report-definitions/{$v1}/new-version")->assertCreated()->assertJsonPath('version', 2)->assertJsonPath('status', 'DRAFT')->assertJsonPath('based_on_id', $v1)->json();
        $this->assertSame(['REV', 'REV_SALES', 'EXP', 'EXP_RENT', 'RESULT'], array_column($draft['lines'], 'code'));
        $this->assertSame('4100', $draft['lines'][1]['mappings'][0]['code'], 'the mapping is copied');
        $this->assertNotSame($this->getJson(self::RP."/report-definitions/{$v1}")->json('lines.1.id'), $draft['lines'][1]['id']);
        $this->postJson(self::RP."/report-definitions/{$v1}/new-version")->assertConflict()->assertJsonPath('code', 'REPORT_DRAFT_EXISTS');
        $this->postJson(self::RP."/report-definitions/{$draft['id']}/new-version")->assertConflict()->assertJsonPath('code', 'REPORT_DEFINITION_IS_DRAFT');

        $line = $draft['lines'][1]['id'];
        $this->patchJson(self::RP."/report-definitions/{$draft['id']}/lines/{$line}", ['label' => 'Penjualan bersih'])->assertOk();

        $this->postJson(self::RP."/report-definitions/{$draft['id']}/publish", ['effective_from' => '2026-02-01'])->assertOk()
            ->assertJsonPath('status', 'ACTIVE')->assertJsonPath('effective_from', '2026-02-01')->assertJsonPath('effective_to', null);
        $old = $this->getJson(self::RP."/report-definitions/{$v1}")->assertOk()->json();
        $this->assertSame('ARCHIVED', $old['status']);
        $this->assertNull($old['effective_from']);
        $this->assertSame('2026-01-31', $old['effective_to']);
        $this->assertSame('REV_SALES', $old['lines'][1]['code']);
        $this->assertSame('REV_SALES', $old['lines'][1]['label'], 'the earlier version keeps its own layout');

        // The version that applied on a date is the one used for it.
        $resolver = app(ReportDefinitionResolver::class);
        $at = fn (string $date) => $this->inTenant($this->tenant, fn () => $resolver->resolve('PROFIT_LOSS', $date)->version);
        $this->assertSame(1, $at('2025-01-01'));
        $this->assertSame(1, $at('2026-01-31'));
        $this->assertSame(2, $at('2026-02-01'));
        $this->assertSame(2, $at('2027-12-31'));

        $this->assertSame(2, $this->auditCount('accounting.report_definition.published'));
        $this->assertSame(1, $this->auditCount('accounting.report_definition.archived'));
    }

    public function test_a_new_version_must_start_after_the_previous_one_and_an_archived_definition_serves_only_its_window(): void
    {
        $v1 = $this->readyDraft();
        $this->postJson(self::RP."/report-definitions/{$v1}/publish", ['effective_from' => '2026-01-01'])->assertOk();
        $v2 = $this->postJson(self::RP."/report-definitions/{$v1}/new-version")->assertCreated()->json('id');

        $this->postJson(self::RP."/report-definitions/{$v2}/publish", ['effective_from' => '2026-01-01'])->assertUnprocessable()->assertJsonPath('code', 'REPORT_EFFECTIVE_FROM_INVALID');
        $this->postJson(self::RP."/report-definitions/{$v2}/publish", ['effective_from' => '2025-06-01'])->assertUnprocessable()->assertJsonPath('code', 'REPORT_EFFECTIVE_FROM_INVALID');
        $this->assertSame('ACTIVE', $this->getJson(self::RP."/report-definitions/{$v1}")->json('status'), 'a refused publish changes nothing');
        $this->assertSame('DRAFT', $this->getJson(self::RP."/report-definitions/{$v2}")->json('status'));

        $this->postJson(self::RP."/report-definitions/{$v2}/publish", ['effective_from' => '2026-04-01'])->assertOk();
        $this->postJson(self::RP."/report-definitions/{$v1}/archive")->assertConflict()->assertJsonPath('code', 'REPORT_DEFINITION_NOT_ACTIVE');
        $this->postJson(self::RP."/report-definitions/{$v2}/archive", ['effective_to' => '2026-03-01'])->assertUnprocessable()->assertJsonPath('code', 'REPORT_EFFECTIVE_TO_INVALID');
        $this->postJson(self::RP."/report-definitions/{$v2}/archive", ['effective_to' => '2026-06-30'])->assertOk()->assertJsonPath('status', 'ARCHIVED')->assertJsonPath('effective_to', '2026-06-30');

        $resolver = app(ReportDefinitionResolver::class);
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => $resolver->resolve('PROFIT_LOSS', '2026-06-30')->version));
        $this->inTenant($this->tenant, function () use ($resolver) {
            try {
                $resolver->resolve('PROFIT_LOSS', '2026-07-01');
                $this->fail('no version applies after the last window');
            } catch (DomainException $e) {
                $this->assertSame('REPORT_DEFINITION_NOT_FOUND', $e->errorCode);
                $this->assertSame(422, $e->status);
            }
        });
        // A definition of another statement type is never returned for an id of this type.
        $this->inTenant($this->tenant, function () use ($resolver, $v1) {
            $this->expectExceptionObject(new DomainException('The report definition does not exist.', 'REPORT_DEFINITION_NOT_FOUND', 404));
            $resolver->resolve('BALANCE_SHEET', '2026-01-01', $v1);
        });
    }

    public function test_a_draft_that_never_took_effect_can_be_deleted_with_its_lines_and_mapping(): void
    {
        $id = $this->readyDraft();
        $this->assertGreaterThan(0, DB::table('report_line_mappings')->where('report_definition_id', $id)->count());

        $this->deleteJson(self::RP."/report-definitions/{$id}")->assertNoContent();

        $this->assertSame(0, DB::table('report_definitions')->where('id', $id)->count());
        $this->assertSame(0, DB::table('report_lines')->where('report_definition_id', $id)->count());
        $this->assertSame(0, DB::table('report_line_mappings')->where('report_definition_id', $id)->count());
        $this->assertSame(1, $this->auditCount('accounting.report_definition.deleted'));
        $this->getJson(self::RP."/report-definitions/{$id}")->assertNotFound();
    }

    // ------------------------------------------------------------------------------------------ editing lines and mappings

    public function test_replacing_the_lines_keeps_the_ids_and_the_mapping_of_the_lines_that_stay(): void
    {
        $id = $this->readyDraft();
        $before = collect($this->getJson(self::RP."/report-definitions/{$id}")->json('lines'))->keyBy('code');

        $lines = $this->profitLossLines();
        $lines[1]['label'] = 'Penjualan';                       // REV_SALES renamed
        $lines = array_values(array_filter($lines, fn ($l) => $l['code'] !== 'EXP_RENT')); // EXP_RENT removed with its mapping
        $lines[] = $this->ln('EXP_SALARY', 'ACCOUNT', 'EXP', ['balance_side' => 'DEBIT']);
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => $lines])->assertOk();

        $after = collect($this->getJson(self::RP."/report-definitions/{$id}")->json('lines'))->keyBy('code');
        $this->assertSame($before['REV_SALES']['id'], $after['REV_SALES']['id']);
        $this->assertSame('Penjualan', $after['REV_SALES']['label']);
        $this->assertSame('4100', $after['REV_SALES']['mappings'][0]['code']);
        $this->assertArrayNotHasKey('EXP_RENT', $after->all());
        $this->assertSame([], $after['EXP_SALARY']['mappings']);
        $this->assertSame(1, DB::table('report_line_mappings')->where('report_definition_id', $id)->count());
    }

    public function test_single_lines_can_be_added_moved_and_removed_on_a_draft(): void
    {
        $id = $this->create()->assertCreated()->json('id');
        $base = self::RP."/report-definitions/{$id}";

        $this->postJson("{$base}/lines", $this->ln('REV', 'SUBTOTAL'))->assertCreated();
        $this->postJson("{$base}/lines", $this->ln('REV', 'SUBTOTAL'))->assertUnprocessable()->assertJsonPath('code', 'REPORT_LINE_CODE_DUPLICATE');
        $this->postJson("{$base}/lines", $this->ln('X', 'ACCOUNT', 'NOPE', ['balance_side' => 'CREDIT']))->assertUnprocessable()->assertJsonPath('code', 'REPORT_LINE_PARENT_UNKNOWN');
        $this->postJson("{$base}/lines", $this->ln('X', 'ACCOUNT', 'REV'))->assertUnprocessable()->assertJsonPath('code', 'REPORT_LINE_INVALID'); // no balance side
        $this->postJson("{$base}/lines", $this->ln('X', 'ACCOUNT', 'REV', ['balance_side' => 'CREDIT']))->assertCreated();
        $this->postJson("{$base}/lines", $this->ln('SUB', 'SUBTOTAL'))->assertCreated();

        $x = $this->getJson($base)->json('lines.1.id');
        $this->patchJson("{$base}/lines/{$x}", ['parent_code' => 'SUB'])->assertOk();
        $this->assertSame('SUB', collect($this->getJson($base)->json('lines'))->firstWhere('code', 'X')['parent_code']);
        $sub = collect($this->getJson($base)->json('lines'))->firstWhere('code', 'SUB')['id'];
        $this->patchJson("{$base}/lines/{$sub}", ['parent_code' => 'X'])->assertUnprocessable(); // X is an ACCOUNT line (and below SUB)
        $this->patchJson("{$base}/lines/{$x}", ['code' => 'RENAMED'])->assertUnprocessable();    // the code is the identity of a line

        $this->deleteJson("{$base}/lines/{$sub}")->assertConflict()->assertJsonPath('code', 'REPORT_LINE_HAS_CHILDREN');
        $this->deleteJson("{$base}/lines/{$x}")->assertOk();
        $this->deleteJson("{$base}/lines/{$sub}")->assertOk();
        $this->assertSame(['REV'], array_column($this->getJson($base)->json('lines'), 'code'));
    }

    public function test_a_line_list_with_a_missing_parent_a_duplicate_code_or_a_loop_is_refused_whole(): void
    {
        $id = $this->create()->assertCreated()->json('id');
        $base = self::RP."/report-definitions/{$id}/lines";

        $this->putJson($base, ['lines' => [$this->ln('A', 'SUBTOTAL', 'GONE')]])->assertUnprocessable()->assertJsonPath('code', 'REPORT_LINE_PARENT_UNKNOWN');
        $this->putJson($base, ['lines' => [$this->ln('A', 'SUBTOTAL'), $this->ln('A', 'HEADER')]])->assertUnprocessable()->assertJsonPath('code', 'REPORT_LINE_CODE_DUPLICATE');
        $this->putJson($base, ['lines' => [$this->ln('A', 'SUBTOTAL', 'B'), $this->ln('B', 'SUBTOTAL', 'A')]])->assertUnprocessable()->assertJsonPath('code', 'LINE_PARENT_CYCLE');
        $this->putJson($base, ['lines' => [$this->ln('A', 'SYSTEM')]])->assertUnprocessable()->assertJsonPath('code', 'REPORT_LINE_INVALID');
        $this->putJson($base, ['lines' => [$this->ln('A', 'CALCULATED_TOTAL')]])->assertUnprocessable()->assertJsonPath('code', 'REPORT_LINE_INVALID');
        $this->putJson($base, ['lines' => [$this->ln('A b', 'HEADER')]])->assertUnprocessable();
        $this->assertSame(0, DB::table('report_lines')->where('report_definition_id', $id)->count(), 'a refused list leaves nothing behind');
    }

    // ------------------------------------------------------------------------------------------ validation findings

    public function test_the_validator_reports_every_structural_error_with_its_code_and_line(): void
    {
        $id = $this->create()->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => [
            $this->ln('SUB_EMPTY', 'SUBTOTAL'),
            $this->ln('HDR', 'HEADER'),
            $this->ln('LEAF', 'ACCOUNT', 'HDR', ['balance_side' => 'CREDIT']),
            $this->ln('UNDER_LEAF', 'ACCOUNT', 'LEAF', ['balance_side' => 'CREDIT']),
            $this->ln('LOOP_A', 'CALCULATED_TOTAL', null, ['operands' => [['line' => 'LOOP_B', 'sign' => '+']]]),
            $this->ln('LOOP_B', 'CALCULATED_TOTAL', null, ['operands' => [['line' => 'LOOP_A', 'sign' => '+']]]),
            $this->ln('SELF', 'CALCULATED_TOTAL', null, ['operands' => [['line' => 'SELF', 'sign' => '+']]]),
            $this->ln('GHOST', 'CALCULATED_TOTAL', null, ['operands' => [['line' => 'NOWHERE', 'sign' => '+']]]),
            $this->ln('ON_HEADER', 'CALCULATED_TOTAL', null, ['operands' => [['line' => 'HDR', 'sign' => '-']]]),
            $this->ln('CAT', 'ACCOUNT', null, ['balance_side' => 'DEBIT', 'cash_flow_category' => 'OPERATING']),
            $this->ln('SYS', 'SYSTEM', null, ['system_key' => 'CASH_OPENING']),
            $this->ln('SYS_AGAIN', 'SYSTEM', null, ['system_key' => 'CASH_OPENING']),
        ]])->assertOk();

        $result = $this->validateIt($id);

        $this->assertFalse($result['valid']);
        $byCode = collect($result['errors'])->groupBy('code');
        foreach (['SUBTOTAL_EMPTY' => 'SUB_EMPTY', 'LINE_PARENT_TYPE_INVALID' => 'UNDER_LEAF', 'OPERAND_SELF_REFERENCE' => 'SELF',
            'OPERAND_LINE_MISSING' => 'GHOST', 'OPERAND_LINE_NOT_NUMERIC' => 'ON_HEADER', 'LINE_CASH_FLOW_CATEGORY_NOT_ALLOWED' => 'CAT', 'LINE_SYSTEM_KEY_INVALID' => 'SYS',
            'SYSTEM_KEY_DUPLICATE' => 'SYS_AGAIN'] as $code => $line) {
            $this->assertTrue($byCode->has($code), "missing {$code}: ".json_encode($this->codes($result['errors'])));
            $this->assertContains($line, $byCode[$code]->pluck('line')->all(), $code);
        }
        $this->assertContains('SELF', $byCode['CALCULATION_CYCLE']->pluck('line')->all());
        $this->assertNotEmpty(array_intersect(['LOOP_A', 'LOOP_B'], $byCode['CALCULATION_CYCLE']->pluck('line')->all()), 'a loop through two totals is found');
        $this->assertContains('LINE_WITHOUT_MAPPING', $this->codes($result['warnings']));
        $this->postJson(self::RP."/report-definitions/{$id}/publish")->assertUnprocessable()->assertJsonPath('code', 'REPORT_DEFINITION_INVALID');
    }

    public function test_each_statement_type_requires_its_own_system_lines_and_cash_flow_accounts_need_a_category(): void
    {
        $bs = $this->create('BALANCE_SHEET', 'Neraca')->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$bs}/lines", ['lines' => [$this->ln('A', 'ACCOUNT', null, ['balance_side' => 'DEBIT'])]])->assertOk();
        $this->assertSame([['REQUIRED_SYSTEM_LINE_MISSING', 'CURRENT_RESULT']], collect($this->validateIt($bs)['errors'])->map(fn ($e) => [$e['code'], $e['details']['system_key']])->all());

        $cf = $this->create('CASH_FLOW', 'Arus kas')->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$cf}/lines", ['lines' => [
            $this->ln('FIN', 'SUBTOTAL'), $this->ln('LOAN', 'ACCOUNT', 'FIN', ['balance_side' => 'CREDIT']),
            $this->ln('NET', 'SYSTEM', null, ['system_key' => 'NET_CASH_CHANGE']),
        ]])->assertOk();
        $result = $this->validateIt($cf);
        $this->assertContains('LINE_CASH_FLOW_CATEGORY_INVALID', $this->codes($result['errors']));
        $missing = collect($result['errors'])->where('code', 'REQUIRED_SYSTEM_LINE_MISSING')->pluck('details.system_key')->sort()->values()->all();
        $this->assertSame(['CASH_CLOSING', 'CASH_OPENING', 'NET_RESULT'], $missing);
        $this->assertContains('UNCLASSIFIED_LINE_MISSING', $this->codes($result['warnings']));
    }

    public function test_the_mapping_is_validated_as_a_whole_before_it_is_stored(): void
    {
        $id = $this->create()->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => [...$this->profitLossLines(), $this->ln('HDR', 'HEADER')]])->assertOk();
        $base = self::RP."/report-definitions/{$id}/mappings";
        $refused = fn (array $mappings) => $this->codes($this->putJson($base, ['mappings' => $mappings])->assertUnprocessable()->assertJsonPath('code', 'REPORT_MAPPING_INVALID')->json('details.errors'));

        $this->assertSame(['MAPPING_LINE_NOT_MAPPABLE'], $refused([$this->map('HDR', '4100')]));
        $this->assertSame(['MAPPING_HEADER_NEEDS_DESCENDANTS'], $refused([$this->map('REV_SALES', '4000')]));
        $this->assertSame(['MAPPING_ACCOUNT_TYPE_INVALID'], $refused([$this->map('REV_SALES', '1110')]));
        $this->assertSame(['MAPPING_ACCOUNT_TYPE_INVALID'], $refused([$this->map('REV_SALES', '1000', true)]), 'every posting account below a header is checked');
        $this->assertContains('MAPPING_DUPLICATE_ACCOUNT', $refused([$this->map('REV_SALES', '4100'), $this->map('REV_SALES', '4100')]));
        $this->assertContains('MAPPING_OVERLAP', $refused([$this->map('REV_SALES', '4000', true), $this->map('EXP_RENT', '4100')]));

        $this->putJson($base, ['mappings' => [$this->map('REV_SALES', '4100')]])->assertOk();
        $inactive = $this->account($this->tenant, '4200');
        $this->postJson(self::RP."/accounts/{$inactive->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->assertSame(['MAPPING_ACCOUNT_INACTIVE'], $refused([$this->map('REV_SALES', '4200')]));
        $this->assertSame(1, DB::table('report_line_mappings')->where('report_definition_id', $id)->count(), 'a refused mapping leaves the stored one untouched');
    }

    public function test_a_cash_account_cannot_be_a_line_of_the_cash_flow_statement(): void
    {
        $id = $this->create('CASH_FLOW', 'Arus kas')->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => [
            $this->ln('OP', 'SUBTOTAL'), $this->ln('CASHLINE', 'ACCOUNT', 'OP', ['balance_side' => 'DEBIT', 'cash_flow_category' => 'OPERATING']),
        ]])->assertOk();

        $errors = $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [$this->map('CASHLINE', '1110')]])->assertUnprocessable()->json('details.errors');

        $this->assertSame(['MAPPING_CASH_ACCOUNT'], $this->codes($errors));
    }

    public function test_a_header_mapped_with_descendants_covers_its_postings_and_an_empty_header_is_only_a_warning(): void
    {
        $id = $this->create()->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => [
            $this->ln('REV', 'SUBTOTAL'), $this->ln('REV_ALL', 'ACCOUNT_GROUP', 'REV', ['balance_side' => 'CREDIT']),
            $this->ln('EXP', 'SUBTOTAL'), $this->ln('EXP_EMPTY', 'ACCOUNT_GROUP', 'EXP', ['balance_side' => 'DEBIT']),
        ]])->assertOk();
        $empty = $this->postJson(self::RP.'/accounts', ['code' => '6800', 'name' => 'Beban kosong', 'account_type' => 'EXPENSE', 'is_postable' => false])->assertCreated()->json('id');

        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [$this->map('REV_ALL', '4000', true), ['line_code' => 'EXP_EMPTY', 'account_id' => $empty, 'include_descendants' => true]]])->assertOk();

        $result = $this->validateIt($id);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertContains('MAPPING_HEADER_EMPTY', $this->codes($result['warnings']));
        $group = collect($this->getJson(self::RP."/report-definitions/{$id}")->json('lines'))->firstWhere('code', 'REV_ALL');
        $this->assertSame('4000', $group['mappings'][0]['code']);
        $this->assertTrue($group['mappings'][0]['include_descendants']);
    }

    public function test_the_validator_warns_about_accounts_with_a_balance_that_no_line_covers_and_lists_them(): void
    {
        $this->standardLedger($this->tenant);
        $id = $this->readyDraft();

        $result = $this->validateIt($id);
        $this->assertTrue($result['valid']);
        $warning = collect($result['warnings'])->firstWhere('code', 'UNMAPPED_ACCOUNT_WITH_BALANCE');
        $this->assertNotNull($warning);
        $this->assertEqualsCanonicalizing(['5100', '6100'], array_column($warning['details']['accounts'], 'code'));

        $list = $this->getJson(self::RP."/report-definitions/{$id}/unmapped-accounts?as_of=2026-12-31")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['5100', '6100'], array_column($list, 'code'));
        $salary = collect($list)->firstWhere('code', '6100');
        $this->assertSame('300000.0000', $salary['balance']);
        $this->assertTrue($salary['has_activity']);

        $this->getJson(self::RP."/report-definitions/{$id}/unmapped-accounts?as_of=2025-12-31&only_with_balance=0")->assertOk();
        $before = $this->getJson(self::RP."/report-definitions/{$id}/unmapped-accounts?as_of=2025-12-31")->assertOk()->json('data');
        $this->assertSame([], $before, 'no expense account without the ledger of the year');
        $this->getJson(self::RP."/report-definitions/{$id}/unmapped-accounts?as_of=not-a-date")->assertUnprocessable();
    }

    // ------------------------------------------------------------------------------------------ tenant safety

    public function test_ids_of_another_tenant_are_not_found_and_nothing_of_theirs_can_be_mapped(): void
    {
        $bravo = $this->reportingTenant('bravo');
        $foreignAccount = $this->account($bravo, '4100')->id;
        $this->signedIn($bravo);
        $foreignDefinition = $this->readyDraftFor($bravo);
        $foreignLine = DB::table('report_lines')->where('report_definition_id', $foreignDefinition)->where('code', 'REV_SALES')->value('id');

        $this->signedIn($this->tenant);
        $id = $this->readyDraft();
        $ownLine = DB::table('report_lines')->where('report_definition_id', $id)->where('code', 'REV_SALES')->value('id');
        $before = DB::table('report_line_mappings')->where('report_definition_id', $id)->pluck('account_id')->sort()->values()->all();

        $this->getJson(self::RP."/report-definitions/{$foreignDefinition}")->assertNotFound();
        $this->postJson(self::RP."/report-definitions/{$foreignDefinition}/publish")->assertNotFound();
        $this->putJson(self::RP."/report-definitions/{$foreignDefinition}/mappings", ['mappings' => []])->assertNotFound();
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [['line_code' => 'REV_SALES', 'account_id' => $foreignAccount]]])->assertNotFound()->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [['report_line_id' => $foreignLine, 'account_id' => $this->account($this->tenant, '4100')->id]]])->assertNotFound()->assertJsonPath('code', 'REPORT_LINE_NOT_FOUND');
        $this->patchJson(self::RP."/report-definitions/{$id}/lines/{$foreignLine}", ['label' => 'x'])->assertNotFound();

        // A line of another definition of the same tenant is not a line of this one.
        $other = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'CASH_FLOW', 'name' => 'Arus kas'])->assertCreated()->json('id');
        $this->patchJson(self::RP."/report-definitions/{$other}/lines/{$ownLine}", ['label' => 'x'])->assertNotFound()->assertJsonPath('code', 'REPORT_LINE_NOT_FOUND');
        $this->deleteJson(self::RP."/report-definitions/{$other}/lines/{$ownLine}")->assertNotFound();

        $this->assertSame($before, DB::table('report_line_mappings')->where('report_definition_id', $id)->pluck('account_id')->sort()->values()->all());
        $this->assertSame(0, DB::table('report_line_mappings')->where('tenant_id', $this->tenant->id)->where('account_id', $foreignAccount)->count());
        $this->assertSame(1, DB::table('report_definitions')->where('tenant_id', $bravo->id)->count());
        $listed = collect($this->getJson(self::RP.'/report-definitions')->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreignDefinition, $listed);
    }

    public function test_the_database_rejects_a_cross_tenant_mapping_even_when_the_application_is_bypassed(): void
    {
        $bravo = $this->reportingTenant('bravo');
        $foreignAccount = $this->account($bravo, '4100')->id;
        $id = $this->readyDraft();
        $line = DB::table('report_lines')->where('report_definition_id', $id)->where('code', 'REV_SALES')->value('id');

        $this->expectException(QueryException::class);
        DB::table('report_line_mappings')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'report_definition_id' => $id, 'report_line_id' => $line,
            'account_id' => $foreignAccount, 'include_descendants' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function readyDraftFor(Tenant $tenant): string
    {
        $id = $this->create()->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => $this->profitLossLines()])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [
            ['line_code' => 'REV_SALES', 'account_id' => $this->account($tenant, '4100')->id], ['line_code' => 'EXP_RENT', 'account_id' => $this->account($tenant, '6200')->id],
        ]])->assertOk();

        return $id;
    }

    // ------------------------------------------------------------------------------------------ database immutability

    public function test_the_database_refuses_to_change_a_published_definition_its_lines_or_its_mapping(): void
    {
        $id = $this->readyDraft();
        $this->postJson(self::RP."/report-definitions/{$id}/publish")->assertOk();
        $line = DB::table('report_lines')->where('report_definition_id', $id)->where('code', 'REV_SALES')->value('id');
        $mapping = DB::table('report_line_mappings')->where('report_definition_id', $id)->value('id');
        $tenant = $this->tenant->id;

        $statements = [
            'rename a line' => fn () => DB::table('report_lines')->where('id', $line)->update(['label' => 'Diubah']),
            'delete a line' => fn () => DB::table('report_lines')->where('id', $line)->delete(),
            'add a line' => fn () => DB::table('report_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'report_definition_id' => $id, 'code' => 'LATE', 'label' => 'Terlambat', 'line_type' => 'HEADER', 'created_at' => now(), 'updated_at' => now()]),
            'remap an account' => fn () => DB::table('report_line_mappings')->where('id', $mapping)->update(['include_descendants' => true]),
            'delete a mapping' => fn () => DB::table('report_line_mappings')->where('id', $mapping)->delete(),
            'rename the definition' => fn () => DB::table('report_definitions')->where('id', $id)->update(['name' => 'Diubah']),
            'move the window' => fn () => DB::table('report_definitions')->where('id', $id)->update(['effective_from' => '2026-01-01']),
            'change the version' => fn () => DB::table('report_definitions')->where('id', $id)->update(['version' => 9]),
            'delete the definition' => fn () => DB::table('report_definitions')->where('id', $id)->delete(),
            'back to draft' => fn () => DB::table('report_definitions')->where('id', $id)->update(['status' => 'DRAFT']),
        ];
        foreach ($statements as $label => $statement) {
            DB::beginTransaction();
            try {
                $statement();
                DB::rollBack();
                $this->fail("the database accepted: {$label}");
            } catch (QueryException $e) {
                DB::rollBack();
                $this->assertSame('23514', $e->errorInfo[0], $label);
            }
        }

        // Archiving is the one allowed change, and an archived definition is final.
        $this->postJson(self::RP."/report-definitions/{$id}/archive", ['effective_to' => '2026-12-31'])->assertOk();
        DB::beginTransaction();
        try {
            DB::table('report_definitions')->where('id', $id)->update(['effective_to' => '2027-12-31']);
            DB::rollBack();
            $this->fail('an archived definition changed');
        } catch (QueryException $e) {
            DB::rollBack();
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame('Laba rugi uji', DB::table('report_definitions')->where('id', $id)->value('name'));
    }

    public function test_the_database_refuses_two_published_versions_that_cover_the_same_date(): void
    {
        $v1 = $this->readyDraft();
        $this->postJson(self::RP."/report-definitions/{$v1}/publish", ['effective_from' => '2026-01-01'])->assertOk();
        $draft = $this->postJson(self::RP."/report-definitions/{$v1}/new-version")->assertCreated()->json('id');

        DB::beginTransaction();
        try {
            DB::table('report_definitions')->where('id', $draft)->update(['status' => 'ACTIVE', 'effective_from' => '2026-06-01']);
            DB::rollBack();
            $this->fail('two active versions overlap');
        } catch (QueryException $e) {
            DB::rollBack();
            $this->assertSame('23P01', $e->errorInfo[0]);
        }
        $this->assertSame('DRAFT', DB::table('report_definitions')->where('id', $draft)->value('status'));
    }

    public function test_the_database_refuses_a_second_row_for_the_same_statement_version(): void
    {
        $this->create()->assertCreated();

        DB::beginTransaction();
        try {
            DB::table('report_definitions')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'statement_type' => 'PROFIT_LOSS', 'code' => 'PROFIT_LOSS', 'name' => 'Dobel',
                'version' => 1, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::rollBack();
            $this->fail('a duplicate version was accepted');
        } catch (QueryException $e) {
            DB::rollBack();
            $this->assertSame('23505', $e->errorInfo[0]);
        }
        $this->assertSame(1, DB::table('report_definitions')->where('tenant_id', $this->tenant->id)->count());
    }

    // ------------------------------------------------------------------------------------------ audit

    public function test_every_change_to_a_definition_is_audited_with_actor_and_before_and_after(): void
    {
        $id = $this->readyDraft();
        $this->patchJson(self::RP."/report-definitions/{$id}", ['name' => 'Laba rugi revisi'])->assertOk();
        $this->postJson(self::RP."/report-definitions/{$id}/publish")->assertOk();
        $this->postJson(self::RP."/report-definitions/{$id}/archive", ['effective_to' => '2026-12-31'])->assertOk();

        $actions = DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('resource_type', 'report_definition')->pluck('action')->countBy()->all();
        $this->assertEquals([
            'accounting.report_definition.created' => 1, 'accounting.report_definition.updated' => 2, 'accounting.report_mapping.changed' => 1,
            'accounting.report_definition.published' => 1, 'accounting.report_definition.archived' => 1,
        ], $actions);

        $published = DB::table('audit_logs')->where('action', 'accounting.report_definition.published')->first();
        $this->assertNotNull($published->actor_user_id);
        $changes = json_decode($published->changes, true);
        $this->assertSame('DRAFT', $changes['before']['status']);
        $this->assertSame('ACTIVE', $changes['after']['status']);
        $this->assertSame($id, $published->resource_id);

        $mapping = json_decode(DB::table('audit_logs')->where('action', 'accounting.report_mapping.changed')->value('changes'), true);
        $this->assertEqualsCanonicalizing(['EXP_RENT <- 6200', 'REV_SALES <- 4100'], $mapping['after']['added']);
    }
}
