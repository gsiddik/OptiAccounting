<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\RoleService;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Support\Money;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Services\ExpenseCategoryService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\ServicePrincipalKinds;
use App\Domain\Identity\Services\ServicePrincipalRegistry;
use App\Domain\Integration\Adapters\OptiFleet\OptiFleetAdapter;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\ConnectionService;
use App\Domain\Integration\Services\EventInbox;
use App\Domain\Integration\Services\EventProcessor;
use App\Domain\Integration\Services\IntegrationRunner;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Models\VendorPayment;
use App\Domain\Payables\Services\ApInvoiceService;
use App\Domain\Payables\Services\VendorPaymentService;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * OA6 demo data for the demo tenant that already has books and payables (called by DemoSeeder after the OA5 data; never by DatabaseSeeder): the
 * OA6 role permissions and an ACTIVE OptiFleet connection whose events went through the real pipeline. The connection is made through
 * ConnectionService with a placeholder credential and a base URL that does not exist, so nothing here (or the scheduler, which finds the
 * connection pulled a moment ago and not due for a day) ever reaches a network. The events are envelopes of OptiFleet's accounting feed handed to
 * EventInbox::receive and EventProcessor::process exactly as a pull hands them; nothing is inserted directly. As in production, the integration
 * user only prepares what the approval policy requires, so the finance manager approves and posts the invoices and the payment it left waiting.
 *
 *   workshop invoice recorded, then paid in full       POSTED events, a posted AP invoice and a posted vendor payment
 *   workshop invoice recorded, then corrected          POSTED events, the first AP invoice REVERSED, the corrected one posted
 *   maintenance memo billed                            POSTED without a journal (information only)
 *   workshop invoice recorded in USD                   NEEDS_REVIEW, CURRENCY_NOT_SUPPORTED
 *   payment of an invoice that was never recorded      NEEDS_REVIEW, INVOICE_NOT_FOUND
 *
 * Dates are relative to today and stay inside the open periods. The connection is the marker: once it exists every section is skipped, so
 * running the seeder again changes nothing.
 */
class DemoOa6Seeder
{
    /** What the bookkeeper may do with OA6: read connections, events, references and source links. */
    public const ACCOUNTANT_PERMISSIONS = ['accounting.integration.view'];

    /** What the finance manager adds: manage connections (and pull now) and retry events. */
    public const MANAGER_PERMISSIONS = ['accounting.integration.manage', 'accounting.integration.retry'];

    /** The OptiFleet company the connection reads from; every demo envelope names it. */
    private const SOURCE_TENANT = 'optifleet-demo-bengkel';

    /** The workshop partner of every demo invoice, as OptiFleet identifies it (payload.vendor). */
    private const VENDOR = ['id' => 'ven-demo-1', 'code' => 'BENGKEL-MITRA', 'name' => 'Bengkel Mitra Sejahtera', 'tax_id' => '09.876.543.2-101.000'];

    private const CATEGORY = ['BENGKEL', 'Servis dan perawatan armada (bengkel)'];

    private TenantContext $context;

    private User $manager;

    private string $timezone;

    private string $today;

    private string $floor;

    private int $sequence = 0;

    public function seed(Tenant $tenant, User $admin, User $manager): void
    {
        $this->context = app(TenantContext::class);
        $this->manager = $manager;
        $this->timezone = $tenant->timezone;

        $this->context->runAs($tenant->id, function () use ($tenant, $admin) {
            $firstOpen = AccountingPeriod::query()->where('status', AccountingPeriod::OPEN)->min('start_date');
            if ($firstOpen === null || ! CashBankAccount::query()->where('code', 'BCA-OPS')->exists() || ! Vendor::query()->exists()) {
                return; // no demo books or payables to build on
            }

            $this->permissions($tenant, $admin);

            $modules = app(ModuleAvailability::class);
            if (IntegrationConnection::query()->exists() || ! $modules->writable(IntegrationRunner::MODULE) || ! $modules->featureEnabled(IntegrationRunner::FEATURE)) {
                return; // already seeded, or the tenant is not entitled to the integration module
            }

            $this->today = now(config('app.timezone'))->toDateString();
            $this->floor = substr((string) $firstOpen, 0, 10);

            // What the platform operator does once per tenant: issue the integration service identity (A23). Without it the connector cannot be switched on.
            app(ServicePrincipalRegistry::class)->issue($tenant, ServicePrincipalKinds::OPTIFLEET_INTEGRATION, 'Demo data: the OptiFleet integration user of the demo organization', 'SEEDER', null, 'DemoSeeder');

            $connection = $this->connection();
            if ($connection !== null) {
                $this->feed($connection);
            }
        });
    }

    /** The OA6 permissions of the demo finance roles; a role of an older demo database gets the missing ones. */
    private function permissions(Tenant $tenant, User $admin): void
    {
        $grants = ['Akuntan' => self::ACCOUNTANT_PERMISSIONS, 'Manajer Keuangan' => [...self::ACCOUNTANT_PERMISSIONS, ...self::MANAGER_PERMISSIONS]];
        foreach ($grants as $name => $wanted) {
            $role = Role::query()->forTenant($tenant->id)->where('name', $name)->first();
            if ($role === null) {
                continue;
            }
            $held = $role->permissions()->pluck('code')->all();
            $missing = array_values(array_diff($wanted, $held));
            if ($missing !== []) {
                app(RoleService::class)->update($role, $admin, null, null, [...$held, ...$missing]);
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ connection

    /**
     * The ACTIVE OptiFleet connection: complete (placeholder credential, fake base URL, cutover on 1 January), pointing at the demo bank account
     * and at an expense category classified to the vehicle maintenance account. Null when the chart has no such account.
     */
    private function connection(): ?IntegrationConnection
    {
        $maintenance = Account::query()->where('code', '6400')->value('id');
        $bank = CashBankAccount::query()->where('code', 'BCA-OPS')->value('id');
        if ($maintenance === null) {
            return null;
        }

        return $this->as($this->manager, function () use ($maintenance, $bank) {
            $category = ExpenseCategory::query()->where('account_id', $maintenance)->where('status', 'ACTIVE')->first()
                ?? app(ExpenseCategoryService::class)->create(['code' => self::CATEGORY[0], 'name' => self::CATEGORY[1], 'account_id' => $maintenance]);

            $connections = app(ConnectionService::class);
            $connection = $connections->create([
                'source_system' => OptiFleetAdapter::SOURCE, 'name' => 'OptiFleet Bengkel', 'source_tenant_reference' => self::SOURCE_TENANT,
                'base_url' => 'https://optifleet.example.test', 'credential' => 'demo-placeholder-not-a-credential',
                'cutover_date' => now(config('app.timezone'))->format('Y').'-01-01', 'pull_interval_minutes' => 1440,
                'settings' => [OptiFleetAdapter::CASH_BANK => $bank, OptiFleetAdapter::CATEGORY => $category->id],
            ]);

            return $connections->activate($connection->id);
        });
    }

    // ------------------------------------------------------------------------------------------------ events

    private function feed(IntegrationConnection $connection): void
    {
        $recorded = 'optifleet.workshop_invoice.recorded';
        $paid = 'optifleet.workshop_invoice.payment_recorded';

        // 1. An invoice is recorded, approved and posted by the finance manager, and then paid in full.
        $first = $this->invoice('BKL-2026-0412', 28, [['Ganti oli mesin dan filter', '2', '350000'], ['Servis rem depan', '1', '1300000']]);
        $this->deliver($connection, $recorded, ['id' => 'wi-demo-1', 'number' => 'BKL-2026-0412'], $first, 28, 'WO-0412', 'B 9041 KXC');
        $this->settleInvoice('BKL-2026-0412');
        $this->deliver($connection, $paid, ['id' => 'wi-demo-1', 'number' => 'BKL-2026-0412'], $first + ['payment' => [
            'id' => 'pay-demo-1', 'amount' => $first['total_amount'], 'payment_date' => $this->date(14), 'method' => 'TRANSFER', 'reference_number' => 'TRF-BKL-0412',
        ]], 14, 'WO-0412', 'B 9041 KXC');
        $this->settlePayment('TRF-BKL-0412');

        // 2. A second invoice is posted and then corrected in OptiFleet: the first document is reversed and the corrected one posted.
        $second = $this->invoice('BKL-2026-0431', 21, [['Overhaul transmisi', '1', '3500000']]);
        $this->deliver($connection, $recorded, ['id' => 'wi-demo-2', 'number' => 'BKL-2026-0431'], $second, 21, 'WO-0431', 'B 2275 TRN');
        $this->settleInvoice('BKL-2026-0431');
        $corrected = $this->invoice('BKL-2026-0431', 21, [['Overhaul transmisi (setelah potongan bengkel)', '1', '3200000']]);
        $this->deliver($connection, 'optifleet.workshop_invoice.corrected', ['id' => 'wi-demo-2', 'number' => 'BKL-2026-0431'], $corrected + [
            'correction' => ['id' => 'cor-demo-1', 'reason' => 'Potongan harga bengkel belum diperhitungkan', 'previous_values' => ['total_amount' => $second['total_amount']], 'requested_values' => ['total_amount' => $corrected['total_amount']]],
        ], 6, 'WO-0431', 'B 2275 TRN');
        $this->settleInvoice('BKL-2026-0431');

        // 3. A maintenance memo is information only: accepted and POSTED without a journal, so the cost is recognised once, at the invoice.
        $this->deliver($connection, 'optifleet.maintenance_memo.billed', ['id' => 'memo-demo-1', 'number' => 'MM-0431', 'type' => 'maintenance_memo'], [
            'informational' => true, 'memo_number' => 'MM-0431', 'memo_cost' => Money::str('3500000'), 'currency' => 'IDR', 'vendor' => self::VENDOR,
            'workshop_invoice' => null, 'snapshot_basis' => 'event_time',
        ], 20, 'WO-0431', 'B 2275 TRN');

        // 4. A US dollar invoice cannot be booked from OptiFleet yet: it waits for a person (CURRENCY_NOT_SUPPORTED).
        $this->deliver($connection, $recorded, ['id' => 'wi-demo-3', 'number' => 'BKL-USD-0007'], $this->invoice('BKL-USD-0007', 8, [['Suku cadang impor (turbocharger)', '1', '850']], 'USD'), 8, 'WO-0455', 'B 7712 LMN');

        // 5. A payment whose invoice OptiEntry never received waits for the missing event (INVOICE_NOT_FOUND).
        $orphan = $this->invoice('BKL-2026-0499', 5, [['Servis AC kabin', '1', '600000']]);
        $this->deliver($connection, $paid, ['id' => 'wi-demo-9', 'number' => 'BKL-2026-0499'], $orphan + ['payment' => [
            'id' => 'pay-demo-9', 'amount' => $orphan['total_amount'], 'payment_date' => $this->date(3), 'method' => 'TRANSFER', 'reference_number' => 'TRF-BKL-0499',
        ]], 3, 'WO-0499', 'B 3308 PQR');

        // The feed is up to date as of the last envelope, so the connection shows where its pull stands.
        app(ConnectionService::class)->pullSucceeded($connection->refresh(), (string) $this->sequence);
    }

    /**
     * Hand one envelope to the inbox and then to the processor, as a pull does. Returns the event as processed.
     *
     * @param  array{id:string,number:string,type?:string}  $source
     * @param  array<string,mixed>  $payload
     */
    private function deliver(IntegrationConnection $connection, string $type, array $source, array $payload, int $daysAgo, string $workOrder, string $registration): ExternalEvent
    {
        $envelope = [
            'sequence' => ++$this->sequence, 'event_id' => sprintf('demo-optifleet-%04d', $this->sequence), 'event_type' => $type, 'schema_version' => '1.0',
            'source_system' => OptiFleetAdapter::SOURCE, 'source_tenant_reference' => self::SOURCE_TENANT,
            'occurred_at' => Carbon::parse($this->date($daysAgo).' 09:30:00', $this->timezone)->toIso8601String(), 'correlation_id' => $workOrder,
            'source' => $source + ['type' => 'workshop_invoice'],
            'dimensions' => ['vehicle' => 'veh-'.strtolower(str_replace(' ', '', $registration)), 'vehicle_registration_number' => $registration, 'work_order' => 'wo-'.strtolower($workOrder),
                'work_order_number' => $workOrder, 'branch' => 'br-jkt', 'branch_code' => 'JKT'],
            'payload' => $payload,
        ];

        $event = app(EventInbox::class)->receive($connection, $envelope)->event;

        return app(EventProcessor::class)->process($event);
    }

    /**
     * The payload shared by the four workshop invoice events, with 11% tax on the lines, in the given currency.
     *
     * @param  list<array{0:string,1:string,2:string}>  $lines  description, quantity, unit price
     * @return array<string,mixed>
     */
    private function invoice(string $number, int $daysAgo, array $lines, string $currency = 'IDR'): array
    {
        $rows = [];
        $subtotal = BigDecimal::zero();
        foreach ($lines as [$description, $quantity, $price]) {
            $total = BigDecimal::of($quantity)->multipliedBy($price);
            $subtotal = $subtotal->plus($total);
            $rows[] = ['description' => $description, 'quantity' => Money::str($quantity), 'unit_price' => Money::str($price), 'line_total' => Money::str($total)];
        }
        $tax = $subtotal->multipliedBy('0.11')->toScale(4, RoundingMode::HalfUp);
        $date = $this->date($daysAgo);

        return [
            'vendor' => self::VENDOR, 'invoice_number' => $number, 'partner_reference' => 'SO-'.substr($number, -4), 'invoice_date' => $date,
            'due_date' => Carbon::parse($date)->addDays(30)->toDateString(), 'currency' => $currency, 'subtotal' => Money::str($subtotal), 'discount_total' => Money::str('0'),
            'tax_total' => Money::str($tax), 'total_amount' => Money::str($subtotal->plus($tax)), 'lines' => $rows, 'snapshot_basis' => 'event_time',
        ];
    }

    // ------------------------------------------------------------------------------------------------ people

    /** The integration user only submits when approval is required; the manager approves and posts what is waiting. */
    private function settleInvoice(string $number): void
    {
        $invoice = ApInvoice::query()->where('vendor_invoice_number', $number)->where('status', ApInvoice::SUBMITTED)->first();
        if ($invoice === null) {
            return;
        }
        $invoices = app(ApInvoiceService::class);
        $this->as($this->manager, function () use ($invoices, $invoice) {
            $invoices->post($invoices->approve($invoice, $this->manager), $this->manager);
        });
    }

    private function settlePayment(string $reference): void
    {
        $payment = VendorPayment::query()->where('reference', $reference)->where('status', VendorPayment::SUBMITTED)->first();
        if ($payment === null) {
            return;
        }
        $payments = app(VendorPaymentService::class);
        $this->as($this->manager, function () use ($payments, $payment) {
            $payments->post($payments->approve($payment, $this->manager), $this->manager);
        });
    }

    private function date(int $daysAgo): string
    {
        return max($this->floor, date('Y-m-d', strtotime($this->today." -{$daysAgo} days")));
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
}
