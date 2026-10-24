<?php

namespace Tests\Support;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Services\ServicePrincipalKinds;
use App\Domain\Identity\Services\ServicePrincipalRegistry;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\PullService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Test helpers for the OptiDeli adapter: a tenant ready to book from OptiDeli, and feed pages built from the contract
 * (OptiDeli docs/integration/OPTI_INTEGRATION_FEED_V1.md) that are pulled through the real FeedClient with the HTTP layer faked.
 * Combine with AccountingFixtures, Fixtures, IntegrationFixtures, PayablesFixtures and ReceivablesFixtures.
 */
trait OptiDeliFixtures
{
    private const DELI_FEED = 'https://deli.example.test/api/v1/integration/feed*';

    /** @var array<string,mixed> the page the faked feed answers with (its events are renumbered from the client's cursor) */
    protected array $deliPage = ['data' => [], 'has_more' => false];

    private int $deliSequence = 0;

    protected function fakeDeli(): void
    {
        Http::fake([self::DELI_FEED => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $after = (int) ($query['after'] ?? 0);
            $data = [];
            foreach (array_values($this->deliPage['data']) as $i => $envelope) {
                $data[] = ['sequence' => $after + $i + 1] + $envelope;
            }
            $last = $after + count($data);

            return Http::response([
                'data' => $data, 'next_cursor' => (string) $last, 'has_more' => false, 'high_water' => (string) max($last, (int) ($this->deliPage['high_water'] ?? 0)),
            ]);
        }]);
    }

    /**
     * A tenant that books from OptiDeli: standard posting rules, a bank account (1120) for receipts, a cash account (1110) for payments, an
     * expense category classified to 6400, and an ACTIVE connection whose settings name them.
     *
     * @param  array<string,string>  $settings  more connection settings (a tax code, ...)
     * @return array{0:Tenant,1:IntegrationConnection}
     */
    protected function deliTenant(string $code = 'alpha', array $profile = ['approval_required' => false], array $settings = []): array
    {
        $tenant = $this->payablesTenant($code, $profile);
        $bank = $this->cashAccount($tenant, 'BCA');
        $cash = $this->cashAccount($tenant, 'KAS', 'CASH');
        $category = $this->expenseCategory($tenant, 'FREIGHT', ['account_id' => $this->account($tenant, '6400')->id]);
        $connection = $this->connectionFor($tenant, [
            'name' => 'OptiDeli', 'source_system' => 'optideli', 'source_tenant_reference' => 'deli-co-1', 'base_url' => 'https://deli.example.test',
            'settings' => $settings + ['receipts_cash_bank_account_id' => $bank->id, 'payables_cash_bank_account_id' => $cash->id, 'expense_category_id' => $category->id],
        ]);
        $this->issueDeliIdentity($tenant);

        return [$tenant, $connection];
    }

    protected function issueDeliIdentity(Tenant $tenant): void
    {
        app(ServicePrincipalRegistry::class)->issue($tenant, ServicePrincipalKinds::OPTIDELI_INTEGRATION, 'test fixture', 'CLI', null, 'phpunit');
    }

    /** @param list<array<string,mixed>> $envelopes */
    protected function pullDeli(Tenant $tenant, IntegrationConnection $connection, array $envelopes): array
    {
        $this->deliPage = ['data' => $envelopes, 'has_more' => false];

        return $this->inTenant($tenant, fn () => app(PullService::class)->pull($connection->refresh()));
    }

    /** @return array<string,mixed> one feed entry about the document `$docId` (its correlation id) */
    protected function deliEvent(string $type, string $docId, string $sourceType, string $sourceNumber, array $payload, array $dimensions = [], ?string $sourceId = null): array
    {
        return [
            'sequence' => ++$this->deliSequence, 'event_id' => (string) Str::uuid(), 'event_type' => $type, 'schema_version' => '1.0', 'source_system' => 'optideli',
            'source_tenant_reference' => 'deli-co-1', 'tenant_id' => 'nexus-tenant', 'occurred_at' => '2026-03-10T08:15:30.123Z', 'correlation_id' => $docId,
            'source' => ['type' => $sourceType, 'id' => $sourceId ?? $docId, 'number' => $sourceNumber],
            'dimensions' => $dimensions + ['order_id' => 'order-1', 'trip_id' => 'trip-1', 'vendor_id' => null, 'freight_invoice_id' => null],
            'payload' => $payload,
        ];
    }

    protected function invoiceIssued(string $docId = 'doc-ar-1', array $payload = []): array
    {
        return $this->deliEvent('optideli.customer_invoice.issued', $docId, 'ledger_document', 'AR-000001', $payload + [
            'document_kind' => 'CUSTOMER_INVOICE', 'currency' => 'IDR', 'amount' => '1110000.00', 'issue_date' => '2026-03-10', 'due_date' => '2026-04-09',
            'counterparty_name' => 'PT Pelanggan Maju', 'customer_name' => 'PT Pelanggan Maju', 'order_number' => 'ORD-001',
        ]);
    }

    protected function billRecorded(string $docId = 'doc-ap-1', array $payload = []): array
    {
        return $this->deliEvent('optideli.vendor_bill.recorded', $docId, 'ledger_document', 'AP-000001', $payload + [
            'document_kind' => 'VENDOR_BILL', 'currency' => 'IDR', 'amount' => '800000.00', 'issue_date' => '2026-03-10', 'due_date' => '2026-04-09',
            'counterparty_name' => 'CV Angkut Jaya', 'vendor_code' => 'ANGKUT', 'vendor_name' => 'CV Angkut Jaya', 'freight_invoice_number' => 'FI-77', 'order_number' => 'ORD-001',
        ], ['vendor_id' => 'ven-1', 'freight_invoice_id' => 'fi-1']);
    }

    protected function paymentRecorded(string $docId, string $direction, string $amount, string $reference = 'TRF-1', array $payload = []): array
    {
        $kind = $direction === 'RECEIPT' ? 'CUSTOMER_INVOICE' : 'VENDOR_BILL';

        return $this->deliEvent('optideli.payment.recorded', $docId, 'ledger_payment', $reference, $payload + [
            'direction' => $direction, 'document_kind' => $kind, 'document_number' => $direction === 'RECEIPT' ? 'AR-000001' : 'AP-000001', 'currency' => 'IDR', 'amount' => $amount,
            'paid_on' => '2026-03-20', 'method' => 'BANK_TRANSFER', 'reference' => $reference, 'outstanding_after' => '0.00', 'document_status' => 'PAID',
        ], sourceId: 'pay-'.$reference);
    }

    protected function documentVoided(string $docId, string $number = 'AR-000001', string $reason = 'Salah pelanggan'): array
    {
        return $this->deliEvent('optideli.document.voided', $docId, 'ledger_document', $number, [
            'document_kind' => 'CUSTOMER_INVOICE', 'currency' => 'IDR', 'amount' => '1110000.00', 'reason' => $reason,
        ]);
    }

    protected function eventRow(array $envelope): object
    {
        return DB::table('external_events')->where('event_id', $envelope['event_id'])->first();
    }

    /** @return list<?string> status and error code of an event, e.g. ['NEEDS_REVIEW', 'PERIOD_CLOSED'] */
    protected function outcomeOf(array $envelope): array
    {
        $row = $this->eventRow($envelope);

        return [$row->status, $row->last_error_code];
    }
}
