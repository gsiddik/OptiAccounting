<?php

namespace App\Domain\Integration\Adapters\OptiDeli;

use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\CashBank\Services\CashBankAccountService;
use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Integration\Contracts\ConnectorRequirements;
use App\Domain\Integration\Contracts\IntegrationAdapter;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Services\ProcessOutcome;
use App\Domain\Shared\DomainException;
use App\Domain\Tax\Models\TaxCode;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * OptiDeli (Opti Integration Feed v1, schema major 1) as a source of receivables and payables: what its sub-ledger says about customer
 * invoices, vendor bills and payments becomes AR / AP documents in OptiEntry's own subledgers, posted through the Posting Engine. The
 * adapter translates business facts and never names an account; tax is computed here, by OptiEntry's tax engine, never by OptiDeli.
 *
 *   optideli.customer_invoice.issued   -> an AR invoice (the customer is resolved by name, see OptiDeliCustomers)
 *   optideli.vendor_bill.recorded      -> an AP invoice (the vendor by source id / tax id / code, see OptiFleetVendors)
 *   optideli.payment.recorded          -> RECEIPT: a customer receipt allocated to the AR invoice, DISBURSEMENT: a vendor payment allocated to the AP invoice
 *   optideli.document.voided           -> the document is reversed (or cancelled when it never posted)
 *
 * Connection settings (ConnectorRequirements): `receipts_cash_bank_account_id` (debited by customer receipts), `payables_cash_bank_account_id`
 * (credited by vendor payments) and `expense_category_id` (classifies the vendor bill) are required; `revenue_account_id` (a REVENUE account;
 * without it the posting rules decide), `output_tax_code_id` and `input_tax_code_id` (INCLUSIVE tax codes applied to the invoice and bill: the
 * amounts of the feed are gross) are optional. The connector needs the OPTIDELI_CONNECTOR feature, and the integration, AR and AP modules writable.
 */
class OptiDeliAdapter implements ConnectorRequirements, IntegrationAdapter
{
    public const SOURCE = 'optideli';

    public const FEATURE = 'OPTIDELI_CONNECTOR';

    public const RECEIPTS_BANK = 'receipts_cash_bank_account_id';

    public const PAYABLES_BANK = 'payables_cash_bank_account_id';

    public const CATEGORY = 'expense_category_id';

    public const REVENUE = 'revenue_account_id';

    public const OUTPUT_TAX = 'output_tax_code_id';

    public const INPUT_TAX = 'input_tax_code_id';

    private const REQUIRED = [self::RECEIPTS_BANK, self::PAYABLES_BANK, self::CATEGORY];

    private const OPTIONAL = [self::REVENUE, self::OUTPUT_TAX, self::INPUT_TAX];

    private const EVENTS = [
        'optideli.customer_invoice.issued' => 'customerInvoice', 'optideli.vendor_bill.recorded' => 'vendorBill',
        'optideli.payment.recorded' => 'payment', 'optideli.document.voided' => 'voided',
    ];

    public function sourceSystem(): string
    {
        return self::SOURCE;
    }

    public function supports(string $eventType, int $major): bool
    {
        return $major === 1 && isset(self::EVENTS[$eventType]);
    }

    public function process(ExternalEvent $event): ProcessOutcome
    {
        $method = self::EVENTS[$event->event_type] ?? throw new DomainException("Unsupported event {$event->event_type}.", 'UNSUPPORTED_EVENT', 422);
        $context = app(TenantContext::class);
        $caller = $context->user();
        $context->setUser(app(OptiDeliActor::class)->resolve()); // the documents are prepared, and audited, by the integration user
        try {
            return app(DeliBooking::class)->{$method}($event, $context->user());
        } finally {
            $context->setUser($caller);
        }
    }

    // ------------------------------------------------------------------------------------------------ connection requirements

    public function cleanSettings(array $settings): array
    {
        $clean = [];
        foreach ($settings as $key => $value) {
            $field = ['field' => "settings.{$key}"];
            if (! in_array($key, [...self::REQUIRED, ...self::OPTIONAL], true)) {
                throw new DomainException("Unknown OptiDeli setting {$key}.", 'CONNECTION_SETTINGS_INVALID', 422, $field);
            }
            if (! is_string($value) || ! Str::isUuid($value)) {
                throw new DomainException("{$key} must be the id of a record of this organization.", 'CONNECTION_SETTINGS_INVALID', 422, $field);
            }
            $clean[$key] = $value;
        }

        // The tenant scope of every lookup is what makes another organization's id "not found".
        foreach ([self::RECEIPTS_BANK, self::PAYABLES_BANK] as $key) {
            if (isset($clean[$key])) {
                app(CashBankAccountService::class)->usable($clean[$key], false, "settings.{$key}");
            }
        }
        if (isset($clean[self::CATEGORY]) && ExpenseCategory::query()->where('status', 'ACTIVE')->whereKey($clean[self::CATEGORY])->doesntExist()) {
            throw new DomainException('The expense category does not exist or is inactive.', 'CONNECTION_SETTINGS_INVALID', 422, ['field' => 'settings.'.self::CATEGORY]);
        }
        if (isset($clean[self::REVENUE])) {
            app(AccountGuard::class)->usable($clean[self::REVENUE], ['REVENUE'], false, 'settings.'.self::REVENUE);
        }
        foreach ([self::OUTPUT_TAX => TaxCode::OUTPUT_TAX, self::INPUT_TAX => TaxCode::INPUT_TAX] as $key => $type) {
            // The feed states gross amounts, so only an inclusive code leaves the document total as the source stated it.
            if (isset($clean[$key]) && TaxCode::query()->where('status', TaxCode::ACTIVE)->where('tax_type', $type)->where('calculation_method', TaxCode::INCLUSIVE)->whereKey($clean[$key])->doesntExist()) {
                throw new DomainException("The tax code must be an active inclusive {$type} code.", 'CONNECTION_SETTINGS_INVALID', 422, ['field' => "settings.{$key}"]);
            }
        }

        return $clean;
    }

    public function missingSettings(array $settings): array
    {
        return self::missing($settings);
    }

    /** @param array<string,mixed> $settings @return list<string> */
    public static function missing(array $settings): array
    {
        return array_values(array_filter(self::REQUIRED, fn (string $key) => ! is_string($settings[$key] ?? null) || $settings[$key] === ''));
    }

    public function entitlementProblems(): array
    {
        $modules = app(ModuleAvailability::class);

        return array_values(array_filter([
            $modules->featureEnabled(self::FEATURE) ? null : 'feature '.self::FEATURE.' is not enabled',
            $modules->writable('ACCOUNTING_INTEGRATION') ? null : 'module ACCOUNTING_INTEGRATION is not available for writing',
            $modules->writable('ACCOUNTING_AP') ? null : 'module ACCOUNTING_AP is not available for writing',
            $modules->writable('ACCOUNTING_AR') ? null : 'module ACCOUNTING_AR is not available for writing',
            app(OptiDeliActor::class)->issued((string) app(TenantContext::class)->tenantId()) ? null : 'the platform operator has not issued the integration service identity of this organization',
        ]));
    }
}
