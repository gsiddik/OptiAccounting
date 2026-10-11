<?php

namespace Tests\Feature\Integration;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\ActorAuthority;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Services\EventProcessor;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Services\VendorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\OptiFleetFixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/**
 * OA6 batch B: the OptiFleet adapter end to end. Feed pages built from the contract go through the real FeedClient (HTTP faked), the inbox,
 * the processor and the existing AP services, so what is asserted is what lands in the AP subledger, the ledger and the audit trail.
 */
class OptiFleetAdapterTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, OptiFleetFixtures, PayablesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeFleet();
    }

    private function invoiceOf(string $number): object
    {
        return DB::table('ap_invoices')->where('vendor_invoice_number', $number)->orderByDesc('created_at')->firstOrFail();
    }

    /** @return array<string,array{0:string,1:string}> account code => [debit, credit] summed over the lines of a journal, ordered by code */
    private function journal(string $id): array
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $id)->groupBy('a.code')->orderBy('a.code')
            ->selectRaw('a.code, sum(l.debit) as debit, sum(l.credit) as credit')->get()
            ->mapWithKeys(fn ($l) => [$l->code => [(string) $l->debit, (string) $l->credit]])->all();
    }

    /** @return array<string,int> row counts of everything an event could create, to prove an event changed nothing */
    private function footprint(): array
    {
        return array_combine($tables = ['ap_invoices', 'vendor_payments', 'vendors', 'journal_entries', 'journal_lines', 'external_source_links', 'external_dimensions', 'users', 'audit_logs'],
            array_map(fn ($t) => DB::table($t)->count(), $tables));
    }

    private function reconciliation(Tenant $tenant, string $asOf = '2026-04-01'): array
    {
        return $this->as($this->memberToken($tenant))->getJson(self::AP."/reconciliation/ap?as_of={$asOf}")->assertOk()->json();
    }

    private function retry(Tenant $tenant, array $envelope)
    {
        return $this->as($this->memberToken($tenant))->postJson(self::IT.'/events/'.$this->eventRow($envelope)->id.'/retry', ['reason' => 'Fixed in OptiEntry']);
    }

    public function test_a_recorded_workshop_invoice_is_posted_to_the_ap_subledger_and_the_ledger_agrees(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $event = $this->recordedEvent();

        $summary = $this->pullFleet($tenant, $connection, [$event]);

        $this->assertSame([1, 1, null], [$summary['received'], $summary['posted'], $summary['error']]);
        $row = $this->eventRow($event);
        $invoice = $this->invoiceOf('INV-001');
        $this->assertSame(['POSTED', $invoice->journal_entry_id], [$row->status, $row->resulting_journal_id]);
        $this->assertSame(['POSTED', 'INVOICE', '2026-03-10', '2026-03-10', '2026-04-09', 'REF-9', '1110000.0000', '110000.0000', '0.0000'],
            [$invoice->status, $invoice->origin, $invoice->document_date, $invoice->posting_date, $invoice->due_date, $invoice->reference, $invoice->total_amount, $invoice->tax_amount, $invoice->discount_amount]);
        $this->assertStringContainsString('WO-001', $invoice->description);

        // Dr maintenance expense (the category's account) and input VAT, Cr the AP control account, all decided by the rule and the mappings.
        $this->assertSame(['1150' => ['110000.0000', '0.0000'], '2110' => ['0.0000', '1110000.0000'], '6400' => ['1000000.0000', '0.0000']], $this->journal($invoice->journal_entry_id));
        $this->assertSame(['1000000.0000', '110000.0000', '-1110000.0000'], [$this->glBalance($tenant, '6400'), $this->glBalance($tenant, '1150'), $this->glBalance($tenant, '2110')]);
        $lines = DB::table('ap_invoice_lines')->where('ap_invoice_id', $invoice->id)->orderBy('line_number')->get();
        $this->assertSame(['500000.0000', '500000.0000'], $lines->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame(1, $lines->pluck('expense_category_id')->unique()->count());
        $this->assertSame(['quantity' => '2.0000', 'unit_price' => '250000.0000'], json_decode($lines[0]->metadata, true));

        $reconciliation = $this->reconciliation($tenant);
        $this->assertSame(['MATCHED', '1110000.0000', '1110000.0000'], [$reconciliation['status'], $reconciliation['gl_balance'], $reconciliation['subledger_balance']]);

        // Traceability: the source document, the event, our document and the journal are linked.
        $link = DB::table('external_source_links')->first();
        $this->assertSame(['optifleet', 'workshop_invoice', 'wi-1', 'INV-001', $event['event_id'], 'ap_invoice', $invoice->id, $invoice->journal_entry_id],
            [$link->source_system, $link->source_type, $link->source_id, $link->source_document_number, $link->source_event_id, $link->document_type, $link->document_id, $link->journal_id]);
        $this->assertSame(1, DB::table('external_source_links')->count());
    }

    public function test_the_integration_user_prepares_and_posts_but_holds_no_permission_and_cannot_sign_in(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $this->pullFleet($tenant, $connection, [$this->recordedEvent()]);

        $email = 'optifleet.integration@alpha.optientry.invalid';
        $user = DB::table('users')->where('email', $email)->first();
        $invoice = $this->invoiceOf('INV-001');
        $this->assertNull($user->password);
        $this->assertSame([$user->id, $user->id], [$invoice->created_by, $invoice->posted_by]);
        $this->assertNull($invoice->approved_by);
        $membership = DB::table('tenant_users')->where('user_id', $user->id)->get();
        $this->assertSame(['ACTIVE', 1], [$membership[0]->status, $membership->count()]);
        $this->assertSame(0, DB::table('tenant_user_roles')->where('tenant_user_id', $membership[0]->id)->count());
        $this->assertSame(['TENANT'], DB::table('data_scopes')->where('tenant_user_id', $membership[0]->id)->pluck('scope_type')->all());
        $this->assertSame($user->id, DB::table('audit_logs')->where('action', 'payables.ap_invoice.posted')->value('actor_user_id'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.integration.actor.created')->count());
        $this->assertSame(DB::table('tenant_users')->where('tenant_id', $tenant->id)->count() - 1, app(CapacityService::class)->used($tenant->id, CapacityService::USER_LIMIT));

        $this->assertNull(app(AuthService::class)->attempt($email, self::PASSWORD));
        $this->inTenant($tenant, function () use ($user) {
            $actor = User::query()->findOrFail($user->id);
            $this->assertFalse(app(ActorAuthority::class)->can($actor, 'accounting.journal.post'));
            $this->assertFalse(app(ActorAuthority::class)->canPostSoftClosed($actor));
        });

        // One user per tenant however many events follow; a person can switch it off, and then nothing is booked.
        $second = $this->recordedEvent(['invoice_number' => 'INV-002'], ['id' => 'wi-2', 'number' => 'INV-002']);
        $this->pullFleet($tenant, $connection, [$second]);
        $this->assertSame(1, DB::table('users')->where('email', $email)->count());
        DB::table('tenant_users')->where('user_id', $user->id)->update(['status' => TenantUser::SUSPENDED]);
        $third = $this->recordedEvent(['invoice_number' => 'INV-003'], ['id' => 'wi-3', 'number' => 'INV-003']);
        $before = $this->footprint();
        $this->pullFleet($tenant, $connection, [$third]);
        $this->assertSame(['NEEDS_REVIEW', 'INTEGRATION_ACTOR_DISABLED'], $this->outcomeOf($third));
        $this->assertSame($before, $this->footprint());
    }

    public function test_a_payment_settles_the_invoice_and_the_open_balance_and_aging_follow(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $second = ['invoice_number' => 'INV-002', 'subtotal' => '500000.0000', 'tax_total' => '55000.0000', 'total_amount' => '555000.0000',
            'lines' => [['description' => 'Servis AC', 'line_total' => '500000.0000']]];
        $events = [
            $this->recordedEvent(),
            $this->recordedEvent($second, ['id' => 'wi-2', 'number' => 'INV-002']),
            $this->paymentEvent(),
            $this->paymentEvent(['id' => 'pay-2', 'amount' => '200000.0000', 'payment_date' => '2026-03-21', 'reference_number' => null], ['id' => 'wi-2', 'number' => 'INV-002']),
        ];

        $summary = $this->pullFleet($tenant, $connection, $events);

        $this->assertSame([4, 4], [$summary['received'], $summary['posted']]);
        $payments = DB::table('vendor_payments')->orderBy('payment_date')->get();
        $this->assertSame(['POSTED', 'POSTED'], $payments->pluck('status')->all());
        $this->assertSame(['1110000.0000', '200000.0000'], $payments->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame(['TRANSFER', 'TRF-7'], [$payments[0]->payment_method, $payments[0]->reference]);
        $this->assertSame('pay-2', $payments[1]->reference); // no reference number: the source payment id identifies it
        $this->assertSame(['1120' => ['0.0000', '1110000.0000'], '2110' => ['1110000.0000', '0.0000']], $this->journal($payments[0]->journal_entry_id));
        $this->assertSame(['-1310000.0000', '-355000.0000'], [$this->glBalance($tenant, '1120'), $this->glBalance($tenant, '2110')]);

        $admin = $this->memberToken($tenant);
        $first = $this->as($admin)->getJson(self::AP.'/ap-invoices/'.$this->invoiceOf('INV-001')->id)->assertOk()->json();
        $this->assertSame(['PAID', '0.0000'], [$first['payment_status'], $first['outstanding_amount']]);
        $open = $this->getJson(self::AP.'/ap-invoices/'.$this->invoiceOf('INV-002')->id)->assertOk()->json();
        $this->assertSame(['PARTIALLY_PAID', '200000.0000', '355000.0000'], [$open['payment_status'], $open['paid_amount'], $open['outstanding_amount']]);
        $aging = $this->getJson(self::AP.'/ap-aging?as_of=2026-04-01')->assertOk()->json();
        $this->assertSame(['355000.0000', 1], [$aging['totals']['total'], $aging['invoice_count']]);
        $reconciliation = $this->reconciliation($tenant);
        $this->assertSame(['MATCHED', '355000.0000'], [$reconciliation['status'], $reconciliation['subledger_balance']]);

        $link = DB::table('external_source_links')->where('source_type', 'workshop_invoice_payment')->where('source_id', 'pay-1')->first();
        $this->assertSame(['vendor_payment', $payments[0]->id, $payments[0]->journal_entry_id], [$link->document_type, $link->document_id, $link->journal_id]);
    }

    public function test_a_payment_before_its_invoice_waits_for_review_and_a_retry_settles_it(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $payment = $this->paymentEvent();

        $this->pullFleet($tenant, $connection, [$payment]);
        $this->assertSame(['NEEDS_REVIEW', 'INVOICE_NOT_FOUND'], $this->outcomeOf($payment));
        $this->assertSame(0, DB::table('vendor_payments')->count());

        $this->pullFleet($tenant, $connection, [$this->recordedEvent()]);
        $this->retry($tenant, $payment)->assertOk()->assertJsonPath('status', 'POSTED');
        $this->assertSame(['POSTED', '0.0000'], [DB::table('vendor_payments')->value('status'), $this->getJson(self::AP.'/ap-invoices/'.$this->invoiceOf('INV-001')->id)->json('outstanding_amount')]);
    }

    public function test_a_corrected_invoice_reverses_the_earlier_document_and_books_the_corrected_one(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $this->pullFleet($tenant, $connection, [$this->recordedEvent()]);
        $first = $this->invoiceOf('INV-001');

        $corrected = $this->fleetEvent('optifleet.workshop_invoice.corrected', $this->fleetPayload([
            'subtotal' => '2000000.0000', 'tax_total' => '220000.0000', 'total_amount' => '2220000.0000',
            'lines' => [['description' => 'Overhaul mesin', 'quantity' => '1.0000', 'unit_price' => '2000000.0000', 'line_total' => '2000000.0000']],
        ]) + ['correction' => ['id' => 'cor-1', 'reason' => 'Harga salah ketik', 'previous_values' => ['total_amount' => '1110000.0000'], 'requested_values' => ['total_amount' => '2220000.0000']]],
            extra: ['occurred_at' => '2026-03-12T09:00:00.000Z']);
        $this->pullFleet($tenant, $connection, [$corrected]);

        $this->assertSame('POSTED', $this->eventRow($corrected)->status);
        $old = DB::table('ap_invoices')->where('id', $first->id)->first();
        $new = DB::table('ap_invoices')->where('id', '<>', $first->id)->first();
        $this->assertSame(['REVERSED', '2026-03-12', 'POSTED', '2220000.0000', 'INV-001'], [$old->status, $old->reversal_posting_date, $new->status, $new->total_amount, $new->vendor_invoice_number]);
        $this->assertStringContainsString('Harga salah ketik', $old->reversal_reason);
        $this->assertSame(['2000000.0000', '220000.0000', '-2220000.0000'], [$this->glBalance($tenant, '6400'), $this->glBalance($tenant, '1150'), $this->glBalance($tenant, '2110')]);
        $this->assertSame($new->journal_entry_id, $this->eventRow($corrected)->resulting_journal_id);
        $this->assertSame(1, DB::table('vendors')->count());
        $this->assertSame([$first->id, $new->id], DB::table('external_source_links')->where('source_type', 'workshop_invoice')->orderBy('created_at')->pluck('document_id')->all());
        $reconciliation = $this->reconciliation($tenant);
        $this->assertSame(['MATCHED', '2220000.0000'], [$reconciliation['status'], $reconciliation['subledger_balance']]);
    }

    public function test_a_cancelled_invoice_is_reversed_and_one_with_an_applied_payment_is_refused(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $cancel = fn (string $id, string $number, bool $hadPayment = false) => $this->fleetEvent('optifleet.workshop_invoice.cancelled', $this->fleetPayload(['invoice_number' => $number])
            + ['cancellation' => ['id' => "can-{$id}", 'reason' => 'Dibatalkan bengkel', 'had_payment' => $hadPayment]], ['id' => $id, 'number' => $number]);
        $second = ['invoice_number' => 'INV-002'];

        $cancelled = $cancel('wi-1', 'INV-001');
        $this->pullFleet($tenant, $connection, [$this->recordedEvent(), $cancelled]);

        $invoice = $this->invoiceOf('INV-001');
        $this->assertSame('REVERSED', $invoice->status);
        $this->assertSame($invoice->reversal_journal_id, $this->eventRow($cancelled)->resulting_journal_id);
        $this->assertSame(['0.0000', '0.0000', '0.0000'], [$this->glBalance($tenant, '6400'), $this->glBalance($tenant, '1150'), $this->glBalance($tenant, '2110')]);
        $this->assertSame('POSTED', $this->eventRow($cancelled)->status);

        // A payment that OptiEntry applied blocks the cancellation: nothing is guessed, the books stay as they were.
        $this->pullFleet($tenant, $connection, [$this->recordedEvent($second, ['id' => 'wi-2', 'number' => 'INV-002']),
            $this->paymentEvent(['id' => 'pay-2'], ['id' => 'wi-2', 'number' => 'INV-002'])]);
        $blocked = $cancel('wi-2', 'INV-002', hadPayment: true);
        $before = $this->footprint();
        $this->pullFleet($tenant, $connection, [$blocked]);
        $this->assertSame(['NEEDS_REVIEW', 'PAYMENT_ALREADY_APPLIED'], $this->outcomeOf($blocked));
        $this->assertSame($before, $this->footprint());
        $this->assertSame('POSTED', $this->invoiceOf('INV-002')->status);

        $unknown = $cancel('wi-9', 'INV-009');
        $this->pullFleet($tenant, $connection, [$unknown]);
        $this->assertSame(['NEEDS_REVIEW', 'INVOICE_NOT_FOUND'], $this->outcomeOf($unknown));
    }

    public function test_replaying_a_page_changes_nothing_and_changed_content_under_one_event_id_is_a_conflict(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $events = [$this->recordedEvent(), $this->paymentEvent()];
        $this->pullFleet($tenant, $connection, $events);
        $before = $this->footprint();
        $stored = $this->eventRow($events[0])->envelope;

        $replay = $this->pullFleet($tenant, $connection, $events);
        $this->assertSame([0, 2, 0, 0], [$replay['received'], $replay['duplicates'], $replay['conflicts'], $replay['posted']]);
        $this->assertSame($before, $this->footprint());

        $changed = $events[0];
        $changed['payload']['total_amount'] = '9999999.0000';
        $conflict = $this->pullFleet($tenant, $connection, [$changed]);
        $this->assertSame([0, 1], [$conflict['received'], $conflict['conflicts']]);
        $row = $this->eventRow($events[0]);
        $this->assertTrue((bool) $row->conflict);
        $this->assertSame($stored, $row->envelope, 'the stored content is untouched');
        $this->assertSame('POSTED', $row->status);
        $this->assertSame(array_diff_key($before, ['audit_logs' => 1]), array_diff_key($this->footprint(), ['audit_logs' => 1]));
        $this->assertSame('1110000.0000', $this->invoiceOf('INV-001')->total_amount);
    }

    public function test_a_period_that_is_not_open_needs_review_and_a_retry_after_reopening_posts(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $move = fn (string $code, string $to) => $this->inTenant($tenant, fn () => app(FiscalCalendarService::class)->transitionPeriod(AccountingPeriod::query()->where('code', $code)->firstOrFail(), $to));
        $march = $this->recordedEvent();
        $february = $this->recordedEvent(['invoice_number' => 'INV-002', 'invoice_date' => '2026-02-10', 'due_date' => '2026-03-10'], ['id' => 'wi-2', 'number' => 'INV-002']);
        $move('2026-03', AccountingPeriod::SOFT_CLOSED);
        $move('2026-02', AccountingPeriod::CLOSED);
        $before = $this->footprint();

        $this->pullFleet($tenant, $connection, [$march, $february]);

        $this->assertSame(['NEEDS_REVIEW', 'PERIOD_SOFT_CLOSED'], $this->outcomeOf($march)); // the integration may not use the soft-closed exception
        $this->assertSame(['NEEDS_REVIEW', 'PERIOD_CLOSED'], $this->outcomeOf($february));
        $this->assertSame($before, $this->footprint(), 'a refused event leaves nothing behind, not even a vendor');

        $move('2026-03', AccountingPeriod::OPEN);
        $this->retry($tenant, $march)->assertOk()->assertJsonPath('status', 'POSTED');
        $this->assertSame('POSTED', $this->invoiceOf('INV-001')->status);
        $this->retry($tenant, $february)->assertOk()->assertJsonPath('last_error_code', 'PERIOD_CLOSED'); // closed stays closed until a person reopens it
        $this->assertSame(['1110000.0000'], [$this->reconciliation($tenant)['subledger_balance']]);
    }

    public function test_an_unmapped_vendor_is_created_once_and_known_vendors_are_matched_not_duplicated(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $this->pullFleet($tenant, $connection, [
            $this->recordedEvent(),
            $this->recordedEvent(['invoice_number' => 'INV-002'], ['id' => 'wi-2', 'number' => 'INV-002']),
        ]);

        $vendor = DB::table('vendors')->first();
        $this->assertSame(1, DB::table('vendors')->count());
        $this->assertSame(['BNK-01', 'Bengkel Maju', '01.234.567.8-901.000', 'optifleet', 'ven-1', 'ACTIVE'], [$vendor->code, $vendor->name, $vendor->tax_id, $vendor->external_source, $vendor->external_id, $vendor->status]);
        $this->assertSame([$vendor->id, $vendor->id], [$this->invoiceOf('INV-001')->vendor_id, $this->invoiceOf('INV-002')->vendor_id]);
        $dimension = DB::table('external_dimensions')->where('entity_type', 'VENDOR')->get();
        $this->assertSame(1, $dimension->count());
        $this->assertSame([$vendor->id, 'ven-1', 'BNK-01'], [json_decode($dimension[0]->metadata, true)['vendor_id'], $dimension[0]->external_id, $dimension[0]->code]);

        // An existing vendor with the same tax id (written differently) is the partner; a code collision with another tax id is not.
        $acme = $this->vendor($tenant, 'ACME', ['tax_id' => '99.888.777.6-543.000']);
        $byTax = $this->recordedEvent(['invoice_number' => 'INV-003', 'vendor' => ['id' => 'ven-2', 'code' => 'XX', 'name' => 'PT Acme', 'tax_id' => '998887776543000']], ['id' => 'wi-3', 'number' => 'INV-003']);
        $byCode = $this->recordedEvent(['invoice_number' => 'INV-004', 'vendor' => ['id' => 'ven-3', 'code' => 'bnk-01', 'name' => 'Bengkel Lain', 'tax_id' => '11.111.111.1-111.000']], ['id' => 'wi-4', 'number' => 'INV-004']);
        $this->pullFleet($tenant, $connection, [$byTax, $byCode]);
        $this->assertSame($acme->id, $this->invoiceOf('INV-003')->vendor_id);
        $this->assertSame('OF-VEN3', DB::table('vendors')->where('id', $this->invoiceOf('INV-004')->vendor_id)->value('code'));
        $this->assertSame(3, DB::table('vendors')->count());

        // Two vendors with one tax id: not guessed. An inactive vendor is not replaced by a new one either.
        $this->vendor($tenant, 'TWIN1', ['tax_id' => '55.555']);
        $this->vendor($tenant, 'TWIN2', ['tax_id' => '55.555']);
        $ambiguous = $this->recordedEvent(['invoice_number' => 'INV-005', 'vendor' => ['id' => 'ven-4', 'code' => 'Y', 'name' => 'Y', 'tax_id' => '55555']], ['id' => 'wi-5', 'number' => 'INV-005']);
        $this->inTenant($tenant, fn () => app(VendorService::class)->setStatus(Vendor::query()->findOrFail($vendor->id), 'INACTIVE'));
        $inactive = $this->recordedEvent(['invoice_number' => 'INV-006'], ['id' => 'wi-6', 'number' => 'INV-006']);
        $count = DB::table('vendors')->count();
        $this->pullFleet($tenant, $connection, [$ambiguous, $inactive]);
        $this->assertSame(['NEEDS_REVIEW', 'VENDOR_AMBIGUOUS'], $this->outcomeOf($ambiguous));
        $this->assertSame(['NEEDS_REVIEW', 'VENDOR_INACTIVE'], $this->outcomeOf($inactive));
        $this->assertSame($count, DB::table('vendors')->count());
    }

    public function test_missing_configuration_surfaces_the_error_of_the_existing_services_as_needs_review(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $before = $this->footprint();

        DB::table('expense_categories')->where('code', 'WORKSHOP')->update(['status' => 'INACTIVE']);
        $inactive = $this->recordedEvent();
        $this->pullFleet($tenant, $connection, [$inactive]);
        $this->assertSame(['NEEDS_REVIEW', 'EXPENSE_CATEGORY_INVALID'], $this->outcomeOf($inactive));
        DB::table('expense_categories')->where('code', 'WORKSHOP')->update(['status' => 'ACTIVE']);

        DB::table('account_mappings')->where('tenant_id', $tenant->id)->where('account_role', 'TAX_RECEIVABLE')->delete();
        $unmapped = $this->recordedEvent(['invoice_number' => 'INV-002'], ['id' => 'wi-2', 'number' => 'INV-002']);
        $this->pullFleet($tenant, $connection, [$unmapped]);
        $this->assertSame(['NEEDS_REVIEW', 'ACCOUNT_MAPPING_MISSING'], $this->outcomeOf($unmapped));

        DB::table('integration_connections')->where('id', $connection->id)->update(['settings' => '{}']);
        $unconfigured = $this->recordedEvent(['invoice_number' => 'INV-003'], ['id' => 'wi-3', 'number' => 'INV-003']);
        $this->pullFleet($tenant, $connection, [$unconfigured]);
        $this->assertSame(['NEEDS_REVIEW', 'CONNECTION_SETTINGS_MISSING'], $this->outcomeOf($unconfigured));
        $this->assertSame($before['ap_invoices'] + $before['journal_entries'], DB::table('ap_invoices')->count() + DB::table('journal_entries')->count());

        // A tenant that never published its AP posting rule.
        $bravo = $this->accountingTenant('bravo', profile: ['approval_required' => false]);
        $bank = $this->cashAccount($bravo, 'BCA');
        $category = $this->expenseCategory($bravo, 'WORKSHOP');
        $other = $this->connectionFor($bravo, ['settings' => ['payables_cash_bank_account_id' => $bank->id, 'expense_category_id' => $category->id]]);
        $ruleless = $this->recordedEvent();
        $this->pullFleet($bravo, $other, [$ruleless]);
        $this->assertSame(['NEEDS_REVIEW', 'POSTING_RULE_NOT_FOUND'], $this->outcomeOf($ruleless));
    }

    public function test_maintenance_memos_are_accepted_without_a_journal_or_any_link(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $memo = fn (string $type) => $this->fleetEvent("optifleet.maintenance_memo.{$type}", [
            'informational' => true, 'memo_number' => 'MM-001', 'memo_cost' => '1000000.0000', 'currency' => 'IDR', 'vendor' => ['id' => 'ven-1', 'code' => 'BNK-01', 'name' => 'Bengkel Maju', 'tax_id' => null],
            'workshop_invoice' => null, 'snapshot_basis' => 'event_time',
        ]);
        $events = [$memo('billed'), $memo('paid')];
        $before = $this->footprint();

        $summary = $this->pullFleet($tenant, $connection, $events);

        $this->assertSame([2, 2], [$summary['received'], $summary['posted']]);
        foreach ($events as $event) {
            $row = $this->eventRow($event);
            $this->assertSame(['POSTED', null, null], [$row->status, $row->resulting_journal_id, $row->last_error_code]);
        }
        $this->assertSame(array_diff_key($before, ['audit_logs' => 1]), array_diff_key($this->footprint(), ['audit_logs' => 1]));
        $this->assertSame(0, DB::table('external_source_links')->count());
    }

    public function test_documents_that_cannot_be_booked_as_stated_need_review_and_never_reach_the_books(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $booked = $this->recordedEvent();
        $this->pullFleet($tenant, $connection, [$booked]);
        $before = $this->footprint();

        $cases = [
            'CURRENCY_NOT_SUPPORTED' => ['currency' => 'USD'],
            'TOTAL_MISMATCH' => ['total_amount' => '1110001.0000'],
            'INVALID_PAYLOAD' => ['invoice_date' => null],
            'AMOUNT_INVALID' => ['lines' => [['description' => 'Terlalu halus', 'line_total' => '1000000.005']]],
            'VENDOR_MISSING' => ['vendor' => null],
            'AP_INVOICE_DUPLICATE' => ['invoice_number' => 'inv-001'], // another source invoice with a number the vendor already has (case and spacing do not matter)
            'LINE_AMOUNT_INVALID' => ['lines' => [['description' => 'Tanpa nilai']]],
        ];
        $events = [];
        foreach (array_keys($cases) as $i => $code) {
            $n = $i + 2;
            $events[$code] = $this->recordedEvent($cases[$code] + ['invoice_number' => "INV-00{$n}"], ['id' => "wi-{$n}", 'number' => "INV-00{$n}"]);
        }
        $this->pullFleet($tenant, $connection, array_values($events));

        foreach ($events as $code => $event) {
            $this->assertSame(['NEEDS_REVIEW', $code], $this->outcomeOf($event), $code);
        }
        $this->assertSame($before, $this->footprint());
    }

    public function test_with_approval_required_the_integration_submits_and_never_approves_and_a_person_finishes_the_job(): void
    {
        [$tenant, $connection] = $this->fleetTenant('alpha', []); // default policy: approval required, preparer cannot approve
        $recorded = $this->recordedEvent();
        $payment = $this->paymentEvent();

        $this->pullFleet($tenant, $connection, [$recorded, $payment]);

        $invoice = $this->invoiceOf('INV-001');
        $this->assertSame(['SUBMITTED', null, null, null], [$invoice->status, $invoice->document_number, $invoice->journal_entry_id, $invoice->approved_by]);
        $row = $this->eventRow($recorded);
        $this->assertSame(['POSTED', null], [$row->status, $row->resulting_journal_id]);
        $this->assertSame($invoice->id, DB::table('external_source_links')->where('source_id', 'wi-1')->value('document_id'));
        $this->assertSame(['NEEDS_REVIEW', 'INVOICE_NOT_POSTED'], $this->outcomeOf($payment));

        $person = $this->memberToken($tenant);
        $this->as($person)->postJson(self::AP."/ap-invoices/{$invoice->id}/approve")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$invoice->id}/post")->assertOk()->assertJsonPath('status', 'POSTED');
        $this->retry($tenant, $payment)->assertOk()->assertJsonPath('status', 'POSTED');
        $settlement = DB::table('vendor_payments')->first();
        $this->assertSame(['SUBMITTED', null], [$settlement->status, $settlement->journal_entry_id]);
        $this->as($person)->postJson(self::AP."/vendor-payments/{$settlement->id}/approve")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$settlement->id}/post")->assertOk();
        $this->assertSame(['PAID', '0.0000'], [$this->getJson(self::AP."/ap-invoices/{$invoice->id}")->json('payment_status'), $this->reconciliation($tenant)['subledger_balance']]);

        // A change that arrives while a document still waits for approval cancels it; nothing was posted, so nothing is reversed.
        $waiting = $this->recordedEvent(['invoice_number' => 'INV-002'], ['id' => 'wi-2', 'number' => 'INV-002']);
        $cancelled = $this->fleetEvent('optifleet.workshop_invoice.cancelled', $this->fleetPayload(['invoice_number' => 'INV-002']) + ['cancellation' => ['id' => 'can-2', 'reason' => 'Salah input', 'had_payment' => false]],
            ['id' => 'wi-2', 'number' => 'INV-002']);
        $this->pullFleet($tenant, $connection, [$waiting, $cancelled]);
        $this->assertSame('CANCELLED', $this->invoiceOf('INV-002')->status);
        $this->assertSame(['POSTED', null], [$this->eventRow($cancelled)->status, $this->eventRow($cancelled)->resulting_journal_id]);
    }

    public function test_when_the_policy_forbids_the_preparer_to_post_the_invoice_stays_a_draft_for_a_person(): void
    {
        [$tenant, $connection] = $this->fleetTenant('alpha', ['approval_required' => false, 'sod_creator_not_poster' => true]);
        $event = $this->recordedEvent();

        $this->pullFleet($tenant, $connection, [$event]);

        $invoice = $this->invoiceOf('INV-001');
        $this->assertSame(['POSTED', 'DRAFT', null], [$this->eventRow($event)->status, $invoice->status, $invoice->journal_entry_id]);
        $this->as($this->memberToken($tenant))->postJson(self::AP."/ap-invoices/{$invoice->id}/post")->assertOk()->assertJsonPath('status', 'POSTED');
    }

    public function test_an_event_of_one_tenant_never_touches_another_tenant(): void
    {
        [$alpha, $alphaConnection] = $this->fleetTenant('alpha');
        [$bravo] = $this->fleetTenant('bravo');
        $tables = ['ap_invoices', 'vendor_payments', 'vendors', 'journal_entries', 'journal_lines', 'external_events', 'external_source_links', 'external_dimensions', 'tenant_users', 'audit_logs'];
        $ownedBy = fn (Tenant $t) => array_map(fn ($table) => DB::table($table)->where('tenant_id', $t->id)->count(), $tables);
        $bravoBefore = $ownedBy($bravo);

        $this->pullFleet($alpha, $alphaConnection, [$this->recordedEvent(), $this->paymentEvent()]);

        $this->assertSame($bravoBefore, $ownedBy($bravo));
        $this->assertSame(0, DB::table('users')->where('email', 'optifleet.integration@bravo.optientry.invalid')->count());
        $this->assertSame(1, DB::table('ap_invoices')->count());

        // Another tenant's cash account is "not found": by the API when it is configured, and by the adapter if it got into the settings anyway.
        $foreignBank = DB::table('cash_bank_accounts')->where('tenant_id', $bravo->id)->value('id');
        $this->as($this->memberToken($alpha))->patchJson(self::IT."/connections/{$alphaConnection->id}", ['settings' => ['payables_cash_bank_account_id' => $foreignBank]])
            ->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        DB::table('integration_connections')->where('id', $alphaConnection->id)->update(['settings' => json_encode(['payables_cash_bank_account_id' => $foreignBank] + $alphaConnection->refresh()->settings)]);
        $this->pullFleet($alpha, $alphaConnection, [$this->recordedEvent(['invoice_number' => 'INV-002'], ['id' => 'wi-2', 'number' => 'INV-002'])]);
        $second = $this->paymentEvent(['id' => 'pay-2'], ['id' => 'wi-2', 'number' => 'INV-002']);
        $this->pullFleet($alpha, $alphaConnection, [$second]);
        $this->assertSame(['NEEDS_REVIEW', 'CASH_BANK_ACCOUNT_NOT_FOUND'], $this->outcomeOf($second));
        $this->assertSame([1, 0], [DB::table('vendor_payments')->where('tenant_id', $alpha->id)->count(), DB::table('vendor_payments')->where('tenant_id', $bravo->id)->count()]);
        $this->assertSame($bravoBefore, $ownedBy($bravo));
    }

    public function test_the_connector_needs_its_feature_and_a_writable_ap_module_to_activate_resume_and_process(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $token = $this->memberToken($tenant);
        $url = self::IT."/connections/{$connection->id}";
        $entitlement = function (string $table, string $key, string $id, string $state) use ($tenant) {
            $this->assertSame(1, DB::table($table)->where('tenant_id', $tenant->id)->where($key, $id)->update(['state' => $state]));
            app(AccessCache::class)->touchTenant($tenant->id);
        };
        $featureId = DB::table('features')->where('code', 'OPTIFLEET_CONNECTOR')->value('id');
        $moduleId = DB::table('modules')->where('code', 'ACCOUNTING_AP')->value('id');
        $lapses = [
            'OPTIFLEET_CONNECTOR' => ['tenant_feature_entitlements', 'feature_id', $featureId, 'DISABLED'],
            'ACCOUNTING_AP' => ['tenant_module_entitlements', 'module_id', $moduleId, 'READ_ONLY'],
        ];

        $n = 0;
        foreach ($lapses as $reason => [$table, $key, $id, $state]) {
            $n++;
            $event = $this->recordedEvent(['invoice_number' => "INV-00{$n}"], ['id' => "wi-{$n}", 'number' => "INV-00{$n}"]);
            $entitlement($table, $key, $id, $state);

            // Not entitled: the event is received but stays exactly as it is. Nothing is booked, not even the integration user, and nothing is flagged as an error.
            $before = $this->footprint();
            $summary = $this->pullFleet($tenant, $connection, [$event]);
            $this->assertSame([1, 0, 'RECEIVED', null], [$summary['received'], $summary['posted'], $this->eventRow($event)->status, $this->eventRow($event)->last_error_code]);
            $this->assertSame($before, $this->footprint());

            // The connection cannot be switched on again while the tenant is not entitled, whichever way it comes back.
            $this->as($token)->postJson("{$url}/pause")->assertOk();
            $this->postJson("{$url}/resume")->assertStatus(403)->assertJsonPath('code', 'CONNECTOR_NOT_ENTITLED');
            $this->assertStringContainsString($reason, $this->postJson("{$url}/resume")->json('details.reasons.0'));
            DB::table('integration_connections')->where('id', $connection->id)->update(['status' => 'READY']);
            $this->postJson("{$url}/activate")->assertStatus(403)->assertJsonPath('code', 'CONNECTOR_NOT_ENTITLED');

            // Entitled again: the connection resumes and the waiting event is booked.
            $entitlement($table, $key, $id, 'ACTIVE');
            $this->postJson("{$url}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
            $this->inTenant($tenant, fn () => app(EventProcessor::class)->process(ExternalEvent::query()->where('event_id', $event['event_id'])->firstOrFail()));
            $this->assertSame('POSTED', $this->eventRow($event)->status);
            $this->assertSame('POSTED', $this->invoiceOf("INV-00{$n}")->status);
        }
        $this->assertSame(2, DB::table('ap_invoices')->count());
    }

    public function test_connection_settings_are_validated_tenant_owned_and_required_to_activate(): void
    {
        [$tenant] = $this->fleetTenant();
        $bank = DB::table('cash_bank_accounts')->where('tenant_id', $tenant->id)->value('id');
        $category = DB::table('expense_categories')->where('tenant_id', $tenant->id)->value('id');
        $foreign = $this->cashAccount($this->payablesTenant('bravo'), 'BCA');
        $retired = $this->expenseCategory($tenant, 'OLD');
        DB::table('expense_categories')->where('id', $retired->id)->update(['status' => 'INACTIVE']);

        $this->as($this->memberToken($tenant));
        $id = $this->postJson(self::IT.'/connections', [
            'name' => 'OptiFleet PT Maju', 'source_system' => 'optifleet', 'source_tenant_reference' => 'fleet-co-9', 'base_url' => 'https://fleet.example.test',
            'credential' => 'feed-secret-123', 'cutover_date' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('status', 'CONFIGURING')->assertJsonPath('settings', [])->json('id');
        $url = self::IT."/connections/{$id}";

        $this->postJson("{$url}/activate")->assertStatus(422)->assertJsonPath('code', 'CONNECTION_INCOMPLETE')
            ->assertJsonPath('details.missing', ['settings.payables_cash_bank_account_id', 'settings.expense_category_id']);
        $this->patchJson($url, ['settings' => ['bogus' => $bank]])->assertStatus(422)->assertJsonPath('code', 'CONNECTION_SETTINGS_INVALID');
        $this->patchJson($url, ['settings' => ['expense_category_id' => 'not-an-id']])->assertStatus(422)->assertJsonPath('code', 'CONNECTION_SETTINGS_INVALID');
        $this->patchJson($url, ['settings' => ['expense_category_id' => $retired->id]])->assertStatus(422)->assertJsonPath('code', 'CONNECTION_SETTINGS_INVALID');
        $this->patchJson($url, ['settings' => ['payables_cash_bank_account_id' => $foreign->id]])->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $this->patchJson($url, ['settings' => ['payables_cash_bank_account_id' => (string) Str::uuid()]])->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $this->patchJson($url, ['settings' => ['expense_category_id' => ['x']]])->assertStatus(422);

        $this->patchJson($url, ['settings' => ['payables_cash_bank_account_id' => $bank]])->assertOk()->assertJsonPath('status', 'CONFIGURING');
        $this->patchJson($url, ['settings' => ['expense_category_id' => $category]])->assertOk()->assertJsonPath('status', 'READY')
            ->assertJsonPath('settings.payables_cash_bank_account_id', $bank)->assertJsonPath('settings.expense_category_id', $category);
        $this->patchJson($url, ['name' => 'Renamed'])->assertOk()->assertJsonPath('settings.expense_category_id', $category); // settings stay when not sent
        $this->postJson("{$url}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->patchJson($url, ['settings' => ['expense_category_id' => null]])->assertStatus(422)->assertJsonPath('code', 'CONNECTION_INCOMPLETE'); // an active connection keeps what it needs
        $this->assertSame($category, DB::table('integration_connections')->where('id', $id)->value('settings') ? json_decode(DB::table('integration_connections')->where('id', $id)->value('settings'), true)['expense_category_id'] : null);
        $this->assertStringContainsString($category, DB::table('audit_logs')->where('resource_id', $id)->where('action', 'accounting.integration.connection.updated')->pluck('changes')->implode(' '));

        // A source without an adapter of its own accepts no settings.
        $this->postJson(self::IT.'/connections', ['name' => 'ERP', 'source_system' => 'erp', 'settings' => ['anything' => 'x']])->assertStatus(422)->assertJsonPath('code', 'CONNECTION_SETTINGS_UNSUPPORTED');
    }
}
