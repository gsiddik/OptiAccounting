<?php

namespace Tests\Feature\Integration;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Adapters\OptiDeli\OptiDeliAdapter;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\OptiDeliFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * Driver cash (uang jalan) from OptiDeli: the disbursement is cash held by the driver (an asset), the settlement turns the spending into trip
 * cost against that advance, and cash given back or paid above the advance are cash receipts and payments on the same advance account.
 */
class OptiDeliCashTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, OptiDeliFixtures, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDeli();
    }

    /** @return array{0:Tenant,1:IntegrationConnection} a tenant whose connection names the cash on hand (1110) and the driver advance account (1160) */
    private function cashTenant(array $profile = ['approval_required' => false], bool $withSettings = true): array
    {
        [$tenant, $connection] = $this->deliTenant('alpha', $profile);
        if ($withSettings) {
            $settings = (array) $connection->settings + [
                OptiDeliAdapter::DRIVER_CASH_BANK => $connection->settings[OptiDeliAdapter::PAYABLES_BANK],
                OptiDeliAdapter::DRIVER_ADVANCE => $this->account($tenant, '1160')->id,
            ];
            $this->inTenant($tenant, fn () => $connection->forceFill(['settings' => $settings])->save());
        }

        return [$tenant, $connection->refresh()];
    }

    /** @return array<string,mixed> */
    private function disbursed(string $voucher = 'cv-1', string $amount = '400000.00'): array
    {
        return $this->deliEvent('optideli.cash.disbursed', $voucher, 'cash_voucher', 'CV-202610-00001', [
            'voucher_number' => 'CV-202610-00001', 'currency' => 'IDR', 'amount' => $amount, 'amount_minor' => (int) round($amount * 100), 'purpose' => 'Uang jalan',
            'driver' => ['name' => 'Budi'], 'cashbox' => ['code' => 'KAS', 'name' => 'Kas utama'],
            'lines' => [['charge_code' => 'TOLL', 'fact_key' => 'TOLL', 'kind' => 'UNPLAN', 'amount' => $amount, 'amount_minor' => (int) round($amount * 100)]],
        ], ['order_id' => 'o1', 'spm_id' => 's1', 'trip_id' => 't1', 'driver_id' => 'd1', 'cashbox_id' => 'c1']);
    }

    /** @return array<string,mixed> */
    private function settled(string $voucher = 'cv-1', string $actual = '380000.00', string $returned = '20000.00', string $reimbursed = '0.00'): array
    {
        return $this->deliEvent('optideli.cash.settled', $voucher, 'cash_voucher', 'CV-202610-00001', [
            'voucher_number' => 'CV-202610-00001', 'currency' => 'IDR', 'disbursed' => '400000.00', 'actual' => $actual, 'returned' => $returned, 'reimbursed' => $reimbursed,
            'driver' => ['name' => 'Budi'], 'cashbox' => ['code' => 'KAS', 'name' => 'Kas utama'],
            'lines' => [
                ['charge_code' => 'TOLL', 'fact_key' => 'TOLL', 'kind' => 'UNPLAN', 'amount' => '300000.00', 'receipt_ref' => 'Struk tol'],
                ['charge_code' => 'UNLOADING', 'fact_key' => 'UNLOADING', 'kind' => 'PLAN', 'amount' => '80000.00', 'receipt_ref' => 'NB-7'],
            ],
        ], ['order_id' => 'o1', 'spm_id' => 's1', 'trip_id' => 't1', 'driver_id' => 'd1', 'cashbox_id' => 'c1']);
    }

    /** Net debit minus credit of an account over the posted journals. */
    private function balance(Tenant $tenant, string $code): string
    {
        $id = $this->account($tenant, $code)->id;
        $row = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $id)->where('journal_entries.status', 'POSTED')->selectRaw('coalesce(sum(debit),0) as d, coalesce(sum(credit),0) as c')->first();

        return number_format((float) $row->d - (float) $row->c, 2, '.', '');
    }

    public function test_a_disbursement_is_cash_held_by_the_driver(): void
    {
        [$tenant, $connection] = $this->cashTenant();
        $event = $this->disbursed();
        $summary = $this->pullDeli($tenant, $connection, [$event]);

        $this->assertSame([1, 1, null], [$summary['received'], $summary['posted'], $summary['error']]);
        $this->assertSame(['POSTED', 'JOURNAL_POSTED'], [$this->eventRow($event)->status, $this->eventRow($event)->accounting_outcome]);
        $transaction = DB::table('cash_transactions')->first();
        $this->assertSame(['PAYMENT', 'POSTED', '400000.0000', 'CV-202610-00001'], [$transaction->kind, $transaction->status, (string) $transaction->amount, $transaction->reference]);
        $this->assertSame('400000.00', $this->balance($tenant, '1160'), 'the advance is an asset held by the driver');
        $this->assertSame('-400000.00', $this->balance($tenant, '1110'));
        $link = DB::table('external_source_links')->first();
        $this->assertSame(['cash_voucher', 'cv-1', 'cash_transaction'], [$link->source_type, $link->source_id, $link->document_type]);
    }

    public function test_a_settlement_books_the_cost_and_the_cash_given_back_and_clears_the_advance(): void
    {
        [$tenant, $connection] = $this->cashTenant();
        $this->pullDeli($tenant, $connection, [$this->disbursed()]);
        $settled = $this->settled();
        $this->pullDeli($tenant, $connection, [$settled]);

        $this->assertSame(['POSTED', 'JOURNAL_POSTED'], [$this->eventRow($settled)->status, $this->eventRow($settled)->accounting_outcome]);
        $this->assertSame('380000.00', $this->balance($tenant, '6400'), 'the trip cost');
        $this->assertSame('0.00', $this->balance($tenant, '1160'), '400 given, 380 spent and 20 given back: the driver holds nothing');
        $this->assertSame(['cash_voucher', 'cash_voucher_expense', 'cash_voucher_return'], DB::table('external_source_links')->orderBy('source_type')->pluck('source_type')->unique()->values()->all());
        $this->assertSame(1, DB::table('cash_transactions')->where('kind', 'RECEIPT')->count());
    }

    public function test_cash_not_given_back_stays_with_the_driver(): void
    {
        [$tenant, $connection] = $this->cashTenant();
        $this->pullDeli($tenant, $connection, [$this->disbursed(), $this->settled(returned: '10000.00')]);
        $this->assertSame('10000.00', $this->balance($tenant, '1160'), '400 given, 380 spent and 10 given back: the driver still holds 10');
    }

    public function test_spending_above_the_advance_is_paid_back_to_the_driver(): void
    {
        [$tenant, $connection] = $this->cashTenant();
        $this->pullDeli($tenant, $connection, [$this->disbursed()]);
        $event = $this->settled(actual: '480000.00', returned: '0.00', reimbursed: '80000.00');
        // lines must add up to the actual amount
        $payload = $event['payload'];
        $payload['lines'][0]['amount'] = '400000.00';
        $event['payload'] = $payload;
        $this->pullDeli($tenant, $connection, [$event]);

        $this->assertSame('POSTED', $this->eventRow($event)->status);
        $this->assertSame('480000.00', $this->balance($tenant, '6400'));
        $this->assertSame('0.00', $this->balance($tenant, '1160'));
        $this->assertSame(1, DB::table('cash_transactions')->where('kind', 'PAYMENT')->where('purpose', 'Pengganti kelebihan uang jalan')->count());
    }

    public function test_lines_that_do_not_add_up_are_refused(): void
    {
        [$tenant, $connection] = $this->cashTenant();
        $this->pullDeli($tenant, $connection, [$this->disbursed()]);
        $event = $this->settled(actual: '390000.00');
        $this->pullDeli($tenant, $connection, [$event]);

        $this->assertSame(['NEEDS_REVIEW', 'TOTAL_MISMATCH'], $this->outcomeOf($event));
        $this->assertSame(0, DB::table('journal_entries')->where('description', 'like', 'Realisasi uang jalan%')->count());
    }

    public function test_without_the_driver_cash_settings_the_event_waits_and_succeeds_on_retry(): void
    {
        [$tenant, $connection] = $this->cashTenant(withSettings: false);
        $event = $this->disbursed();
        $this->pullDeli($tenant, $connection, [$event]);
        $this->assertSame(['NEEDS_REVIEW', 'CONNECTION_SETTINGS_MISSING'], $this->outcomeOf($event));

        $settings = (array) $connection->settings + [
            OptiDeliAdapter::DRIVER_CASH_BANK => $connection->settings[OptiDeliAdapter::PAYABLES_BANK], OptiDeliAdapter::DRIVER_ADVANCE => $this->account($tenant, '1160')->id,
        ];
        $this->inTenant($tenant, fn () => $connection->forceFill(['settings' => $settings])->save());
        $this->asMember($tenant)->postJson(self::IT.'/events/'.$this->eventRow($event)->id.'/retry', ['reason' => 'Settings added'])->assertOk();
        $this->assertSame(['POSTED', null], $this->outcomeOf($event));
        $this->assertSame(1, DB::table('cash_transactions')->count());
    }

    public function test_the_advance_must_be_an_asset_account(): void
    {
        [$tenant, $connection] = $this->cashTenant();
        $this->expectException(DomainException::class);
        $this->inTenant($tenant, fn () => app(OptiDeliAdapter::class)->cleanSettings([OptiDeliAdapter::DRIVER_ADVANCE => $this->account($tenant, '6400')->id]));
    }

    public function test_with_approval_required_the_journal_waits_for_an_approver(): void
    {
        [$tenant, $connection] = $this->cashTenant(['approval_required' => true]);
        $this->pullDeli($tenant, $connection, [$this->disbursed()]);
        $event = $this->settled(returned: '20000.00');
        $this->pullDeli($tenant, $connection, [$event]);

        $row = $this->eventRow($event);
        $this->assertSame('POSTED', $row->status);
        $this->assertContains($row->accounting_outcome, ['AWAITING_APPROVAL', 'DRAFT_DOCUMENT']);
        $this->assertSame('0.00', $this->balance($tenant, '6400'), 'nothing is in the ledger before somebody approves');
    }
}
