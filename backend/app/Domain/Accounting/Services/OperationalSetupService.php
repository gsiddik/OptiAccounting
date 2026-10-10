<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\PostingRule;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The posting rules the OA2 documents need, as a ready-made set a tenant can apply with one action (and still edit by publishing
 * new versions). Applying is explicit and idempotent: an event type that already has a published rule is left alone, nothing is
 * ever overwritten, and a rule whose mapped roles are missing is reported instead of half-created.
 */
class OperationalSetupService
{
    private const EXPENSE_LINES = [
        ['DEBIT', 'EXPENSE', 'net', 'Beban atau aset'], ['DEBIT', 'TAX_RECEIVABLE', 'tax', 'PPN masukan'],
    ];

    /** event type => [code, name, lines [side, role, component, description]] */
    public const RULES = [
        'AP_INVOICE_RECOGNIZED' => ['AP-INVOICE', 'Faktur vendor diakui', [
            ['DEBIT', 'EXPENSE', 'net', 'Beban atau aset'], ['DEBIT', 'TAX_RECEIVABLE', 'tax', 'PPN masukan'], ['CREDIT', 'ACCOUNTS_PAYABLE', 'total', 'Utang usaha'],
        ]],
        'VENDOR_PAYMENT' => ['AP-PAYMENT', 'Pembayaran vendor', [
            ['DEBIT', 'ACCOUNTS_PAYABLE', 'amount', 'Pelunasan utang usaha'], ['CREDIT', 'CASH_BANK_ACCOUNT', 'amount', 'Kas atau bank'],
        ]],
        'EXPENSE_RECOGNIZED' => ['EXPENSE-PAYABLE', 'Beban diakui sebagai utang', [
            ['DEBIT', 'EXPENSE', 'net', 'Beban'], ['DEBIT', 'TAX_RECEIVABLE', 'tax', 'PPN masukan'], ['CREDIT', 'ACCOUNTS_PAYABLE', 'total', 'Utang usaha'],
        ]],
        'EXPENSE_PAID' => ['EXPENSE-PAID', 'Beban dibayar langsung', [
            ['DEBIT', 'EXPENSE', 'net', 'Beban'], ['DEBIT', 'TAX_RECEIVABLE', 'tax', 'PPN masukan'], ['CREDIT', 'CASH_BANK_ACCOUNT', 'total', 'Kas atau bank'],
        ]],
        'CASH_PAYMENT' => ['CASH-PAYMENT', 'Pembayaran kas/bank', [
            ['DEBIT', 'DOCUMENT_ACCOUNT', 'amount', 'Akun tujuan'], ['CREDIT', 'CASH_BANK_ACCOUNT', 'amount', 'Kas atau bank'],
        ]],
        'CASH_RECEIPT' => ['CASH-RECEIPT', 'Penerimaan kas/bank', [
            ['DEBIT', 'CASH_BANK_ACCOUNT', 'amount', 'Kas atau bank'], ['CREDIT', 'DOCUMENT_ACCOUNT', 'amount', 'Akun sumber'],
        ]],
    ];

    public function __construct(private readonly PostingRuleService $rules, private readonly AccountMappingService $mappings) {}

    /** @return list<array{event_type:string,name:string,ready:bool,rule_code:?string,effective_from:?string}> which OA2 events can post today */
    public function status(): array
    {
        $published = PostingRule::query()->where('status', PostingRule::PUBLISHED)->get()->groupBy('event_type');

        return collect(self::RULES)->map(fn ($def, $event) => [
            'event_type' => $event, 'name' => $def[1], 'ready' => $published->has($event),
            'rule_code' => $published->get($event)?->first()?->code, 'effective_from' => $published->get($event)?->first()?->effective_from?->toDateString(),
        ])->values()->all();
    }

    /**
     * @return array{created:list<string>,skipped:list<array{event_type:string,reason:string}>}
     */
    public function applyDefaults(?string $effectiveFrom = null): array
    {
        $from = $effectiveFrom ?? FiscalYear::query()->min('start_date');
        if ($from === null) {
            throw new DomainException('Create a fiscal year before applying the default posting rules.', 'FISCAL_YEAR_MISSING', 409);
        }
        $from = substr((string) $from, 0, 10);

        $created = [];
        $skipped = [];
        foreach (self::RULES as $event => [$code, $name, $lines]) {
            if (PostingRule::query()->where('event_type', $event)->where('status', PostingRule::PUBLISHED)->exists()) {
                $skipped[] = ['event_type' => $event, 'reason' => 'ALREADY_PUBLISHED'];

                continue;
            }
            if (PostingRule::query()->where('code', $code)->exists()) {
                $skipped[] = ['event_type' => $event, 'reason' => 'CODE_TAKEN'];

                continue;
            }

            try {
                DB::transaction(function () use ($event, $code, $name, $lines, $from) {
                    $rule = $this->rules->create(['code' => $code, 'event_type' => $event, 'name' => $name, 'lines' => array_map(
                        fn ($l) => ['side' => $l[0], 'account_role' => $l[1], 'amount_key' => $l[2], 'description' => $l[3], 'skip_if_zero' => true], $lines)]);
                    $this->rules->publish($rule, $from);
                });
                $created[] = $event;
            } catch (DomainException $e) {
                $skipped[] = ['event_type' => $event, 'reason' => $e->errorCode];
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
