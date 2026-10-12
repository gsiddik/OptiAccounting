<?php

namespace App\Domain\Integration\Adapters\OptiFleet;

use App\Domain\CashBank\Services\CashBankAccountService;
use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Integration\Contracts\ConnectorRequirements;
use App\Domain\Integration\Contracts\IntegrationAdapter;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Services\ProcessOutcome;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * OptiFleet (schema major 1) as a source of payables: what its accounting feed says about external workshop invoices becomes AP
 * documents in OptiEntry's own subledger, posted through the Posting Engine. The adapter translates business facts and never names an
 * account; the debit side comes from the connection's expense category (an account or role of the tenant) and the posting rules.
 *
 *   optifleet.workshop_invoice.recorded | corrected | cancelled | payment_recorded   -> WorkshopInvoiceBooking
 *   optifleet.maintenance_memo.billed | paid                                          -> accepted and POSTED with no journal and no links:
 *       the cost is recognised once, at the workshop invoice (ROADMAP open decision 3); a memo is information, so it cannot double-count it
 *
 * Not in the feed yet, so not handled: vendor (procurement) invoices and payments, goods receipt, stock issue, spare part sale.
 *
 * The adapter holds no state and no service: the registry keeps one instance for all tenants, so everything tenant-bound is resolved from
 * the container per event (a fresh object graph with the tenant and actor of that moment).
 *
 * Connection settings (ConnectorRequirements): `payables_cash_bank_account_id` (credited by vendor payments) and `expense_category_id`
 * (classifies every invoice line), ids of the tenant's own records. The connector needs the OPTIFLEET_CONNECTOR feature, and the integration
 * and AP modules writable.
 */
class OptiFleetAdapter implements ConnectorRequirements, IntegrationAdapter
{
    public const SOURCE = 'optifleet';

    public const FEATURE = 'OPTIFLEET_CONNECTOR';

    public const CASH_BANK = 'payables_cash_bank_account_id';

    public const CATEGORY = 'expense_category_id';

    private const SETTINGS = [self::CASH_BANK, self::CATEGORY];

    private const MEMOS = ['optifleet.maintenance_memo.billed', 'optifleet.maintenance_memo.paid'];

    private const INVOICE_EVENTS = [
        'optifleet.workshop_invoice.recorded' => 'recorded', 'optifleet.workshop_invoice.corrected' => 'corrected',
        'optifleet.workshop_invoice.cancelled' => 'cancelled', 'optifleet.workshop_invoice.payment_recorded' => 'paid',
    ];

    public function sourceSystem(): string
    {
        return self::SOURCE;
    }

    public function supports(string $eventType, int $major): bool
    {
        return $major === 1 && (isset(self::INVOICE_EVENTS[$eventType]) || in_array($eventType, self::MEMOS, true));
    }

    public function process(ExternalEvent $event): ProcessOutcome
    {
        if (in_array($event->event_type, self::MEMOS, true)) {
            return new ProcessOutcome(outcome: ProcessOutcome::INFORMATIONAL); // recorded as a fact; deliberately books nothing
        }

        $method = self::INVOICE_EVENTS[$event->event_type] ?? throw new DomainException("Unsupported event {$event->event_type}.", 'UNSUPPORTED_EVENT', 422);
        $context = app(TenantContext::class);
        $caller = $context->user();
        $context->setUser(app(OptiFleetActor::class)->resolve()); // the documents are prepared, and audited, by the integration user
        try {
            return app(WorkshopInvoiceBooking::class)->{$method}($event, $context->user());
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
            if (! in_array($key, self::SETTINGS, true)) {
                throw new DomainException("Unknown OptiFleet setting {$key}.", 'CONNECTION_SETTINGS_INVALID', 422, $field);
            }
            if (! is_string($value) || ! Str::isUuid($value)) {
                throw new DomainException("{$key} must be the id of a record of this organization.", 'CONNECTION_SETTINGS_INVALID', 422, $field);
            }
            $clean[$key] = $value;
        }

        // The tenant scope of both lookups is what makes another organization's id "not found".
        if (isset($clean[self::CASH_BANK])) {
            app(CashBankAccountService::class)->usable($clean[self::CASH_BANK], false, 'settings.'.self::CASH_BANK);
        }
        if (isset($clean[self::CATEGORY]) && ExpenseCategory::query()->where('status', 'ACTIVE')->whereKey($clean[self::CATEGORY])->doesntExist()) {
            throw new DomainException('The expense category does not exist or is inactive.', 'CONNECTION_SETTINGS_INVALID', 422, ['field' => 'settings.'.self::CATEGORY]);
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
        return array_values(array_filter(self::SETTINGS, fn (string $key) => ! is_string($settings[$key] ?? null) || $settings[$key] === ''));
    }

    public function entitlementProblems(): array
    {
        $modules = app(ModuleAvailability::class);

        return array_values(array_filter([
            $modules->featureEnabled(self::FEATURE) ? null : 'feature '.self::FEATURE.' is not enabled',
            $modules->writable('ACCOUNTING_INTEGRATION') ? null : 'module ACCOUNTING_INTEGRATION is not available for writing',
            $modules->writable('ACCOUNTING_AP') ? null : 'module ACCOUNTING_AP is not available for writing',
        ]));
    }
}
