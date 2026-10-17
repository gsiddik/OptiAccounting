<?php

namespace Tests\Support;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\PullService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Test helpers for the OptiFleet adapter: a tenant ready to book from OptiFleet, and feed pages built from the contract
 * (OptiFleet-v2 docs/integration/ACCOUNTING_FEED.md) that are pulled through the real FeedClient with the HTTP layer faked.
 * Combine with AccountingFixtures, Fixtures, IntegrationFixtures and PayablesFixtures.
 */
trait OptiFleetFixtures
{
    private const FLEET_FEED = 'https://fleet.example.test/api/v1/integration/accounting-feed*';

    /** @var array<string,mixed> the page the faked feed answers with (its events are renumbered from the client's cursor, see fakeFleet) */
    protected array $fleetPage = ['data' => [], 'has_more' => false];

    private int $fleetSequence = 0;

    /**
     * The faked feed serves `$fleetPage` the way a real source would: the events are numbered from the `after` the client asked for (the feed is
     * gap-free, so a page always continues the cursor), `next_cursor` is the last number and `high_water` the newest committed sequence.
     */
    protected function fakeFleet(): void
    {
        Http::fake([self::FLEET_FEED => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $after = (int) ($query['after'] ?? 0);
            $data = [];
            foreach (array_values($this->fleetPage['data']) as $i => $envelope) {
                $data[] = ['sequence' => $after + $i + 1] + $envelope;
            }
            $last = $after + count($data);

            return Http::response([
                'data' => $data, 'next_cursor' => $data === [] ? null : (string) $last, 'has_more' => false, 'high_water' => (string) max($last, (int) ($this->fleetPage['high_water'] ?? 0)),
            ]);
        }]);
    }

    /**
     * A tenant that books from OptiFleet: standard AP posting rules, a bank account (1120), an expense category classified to 6400, and an
     * ACTIVE connection whose settings name both. Approval is not required unless the profile says so.
     *
     * @return array{0:Tenant,1:IntegrationConnection}
     */
    protected function fleetTenant(string $code = 'alpha', array $profile = ['approval_required' => false]): array
    {
        $tenant = $this->payablesTenant($code, $profile);
        $bank = $this->cashAccount($tenant, 'BCA');
        $category = $this->expenseCategory($tenant, 'WORKSHOP', ['account_id' => $this->account($tenant, '6400')->id]);
        $connection = $this->connectionFor($tenant, ['settings' => ['payables_cash_bank_account_id' => $bank->id, 'expense_category_id' => $category->id]]);
        $this->issueFleetIdentity($tenant);

        return [$tenant, $connection];
    }

    /** Put the pages' events on the faked feed and run one real pull (fetch, store, process) of the connection. @param list<array<string,mixed>> $envelopes */
    protected function pullFleet(Tenant $tenant, IntegrationConnection $connection, array $envelopes): array
    {
        $this->fleetPage = ['data' => $envelopes, 'has_more' => false];

        return $this->inTenant($tenant, fn () => app(PullService::class)->pull($connection->refresh()));
    }

    /** @return array<string,mixed> the common payload of the four workshop_invoice events: Rp 1.110.000 (two lines of Rp 500.000 plus tax Rp 110.000) */
    protected function fleetPayload(array $override = []): array
    {
        return $override + [
            'vendor' => ['id' => 'ven-1', 'code' => 'BNK-01', 'name' => 'Bengkel Maju', 'tax_id' => '01.234.567.8-901.000'],
            'invoice_number' => 'INV-001', 'partner_reference' => 'REF-9', 'invoice_date' => '2026-03-10', 'due_date' => '2026-04-09', 'currency' => 'IDR',
            'subtotal' => '1000000.0000', 'discount_total' => '0.0000', 'tax_total' => '110000.0000', 'total_amount' => '1110000.0000',
            'lines' => [
                ['description' => 'Ganti oli mesin', 'quantity' => '2.0000', 'unit_price' => '250000.0000', 'line_total' => '500000.0000'],
                ['description' => 'Servis rem', 'quantity' => '1.0000', 'unit_price' => '500000.0000', 'line_total' => '500000.0000'],
            ],
            'maintenance_memo' => ['id' => 'memo-1', 'number' => 'MM-001'], 'snapshot_basis' => 'event_time',
        ];
    }

    /** @return array<string,mixed> one feed entry; `$source` overrides the source invoice, `$extra` the envelope */
    protected function fleetEvent(string $type, array $payload = [], array $source = [], array $extra = []): array
    {
        $memo = str_contains($type, 'maintenance_memo');

        return $extra + [
            'sequence' => ++$this->fleetSequence, 'event_id' => 'evt-'.Str::uuid(), 'event_type' => $type, 'schema_version' => '1.0', 'source_system' => 'optifleet',
            'source_tenant_reference' => 'fleet-co-1', 'occurred_at' => '2026-03-10T08:15:30.123Z', 'correlation_id' => 'wo-1',
            'source' => $source + ['type' => $memo ? 'maintenance_memo' : 'workshop_invoice', 'id' => 'wi-1', 'number' => $memo ? 'MM-001' : 'INV-001'],
            'dimensions' => ['vehicle' => 'veh-1', 'vehicle_registration_number' => 'B 1234 XY', 'work_order' => 'wo-1', 'work_order_number' => 'WO-001', 'branch' => 'br-1', 'branch_code' => 'JKT'],
            'payload' => $payload,
        ];
    }

    protected function recordedEvent(array $payload = [], array $source = [], array $extra = []): array
    {
        return $this->fleetEvent('optifleet.workshop_invoice.recorded', $this->fleetPayload($payload), $source, $extra);
    }

    protected function paymentEvent(array $payment = [], array $source = [], array $payload = [], array $extra = []): array
    {
        return $this->fleetEvent('optifleet.workshop_invoice.payment_recorded', $this->fleetPayload($payload) + [
            'payment' => $payment + ['id' => 'pay-1', 'amount' => '1110000.0000', 'payment_date' => '2026-03-20', 'method' => 'TRANSFER', 'reference_number' => 'TRF-7'],
        ], $source, $extra);
    }

    protected function eventRow(array $envelope): object
    {
        return DB::table('external_events')->where('event_id', $envelope['event_id'])->first();
    }

    /** @return list<string> status and error code of an event, e.g. ['NEEDS_REVIEW', 'PERIOD_CLOSED'] */
    protected function outcomeOf(array $envelope): array
    {
        $row = $this->eventRow($envelope);

        return [$row->status, $row->last_error_code];
    }
}
