<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\RoleService;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Closing\Models\ClosingChecklistTemplate;
use App\Domain\Closing\Models\ClosingEvent;
use App\Domain\Closing\Services\ChecklistService;
use App\Domain\Closing\Services\PeriodClosingService;
use App\Domain\Closing\Services\YearEndSetupService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Reconciliation\Services\ReconciliationEngine;
use App\Domain\Reconciliation\Services\ReconciliationRunService;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Services\ReportDefaultsService;
use App\Domain\Reporting\Services\ReportDefinitionService;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;

/**
 * OA5 demo data for the demo tenant that already has books, payables, receivables and the OA4 modules (called by DemoSeeder after the OA4 data;
 * never by DatabaseSeeder): the OA5 role permissions, the published default balance sheet / profit and loss / cash flow definitions, the year-end
 * closing rule, two manual checklist items, a first set of saved reconciliations and one hard-closed period with its closing history. Everything
 * goes through the same services as the API, so every invariant applies to this data too. No year-end closing is run: the demo fiscal year is
 * still open and the screen shows its preview. Every section skips itself once its marker exists, so running the seeder again changes nothing.
 */
class DemoOa5Seeder
{
    /** What the bookkeeper may do with OA5: read every statement, reconciliation and the closing state, and run a reconciliation. */
    public const ACCOUNTANT_PERMISSIONS = [
        'accounting.reporting.balance_sheet.view', 'accounting.reporting.pnl.view', 'accounting.reporting.cash_flow.view', 'accounting.report_definition.view',
        'accounting.reconciliation.view', 'accounting.reconciliation.run', 'accounting.period.preclose', 'accounting.year_end.view',
    ];

    /** What the finance manager adds: report definitions, reconciliation exceptions, the closing policy and checklist, closing, reopening and year end. */
    public const MANAGER_PERMISSIONS = [
        'accounting.report_definition.manage', 'accounting.reconciliation.exception.manage', 'accounting.closing.checklist.manage', 'accounting.closing.policy.manage',
        'accounting.period.soft_close', 'accounting.period.hard_close', 'accounting.period.reopen', 'accounting.year_end.run',
    ];

    /** [code, title, description] of the manual closing checklist items. */
    private const CHECKLIST = [
        ['REKON_BANK_TTD', 'Rekonsiliasi bank ditandatangani', 'Lembar rekonsiliasi bank periode ini sudah diperiksa dan ditandatangani.'],
        ['REVIEW_JURNAL_PENYESUAIAN', 'Jurnal penyesuaian ditinjau', 'Jurnal penyesuaian akhir periode sudah ditinjau oleh manajer keuangan.'],
    ];

    private TenantContext $context;

    private User $accountant;

    private User $manager;

    public function seed(Tenant $tenant, User $admin, User $accountant, User $manager): void
    {
        $this->context = app(TenantContext::class);
        $this->accountant = $accountant;
        $this->manager = $manager;

        $this->context->runAs($tenant->id, function () use ($tenant, $admin) {
            $february = AccountingPeriod::query()->where('code', now(config('app.timezone'))->format('Y').'-02')->first();
            if ($february === null) {
                return; // no demo books to build on
            }

            $this->permissions($tenant, $admin);
            $this->reportDefinitions();
            $this->yearEndRule();
            $this->checklist();
            $this->reconciliations(now(config('app.timezone'))->toDateString());
            $this->closeFebruary($february);
        });
    }

    /** The OA5 permissions of the demo finance roles; a role of an older demo database gets the missing ones. */
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

    /** The default layouts are built from the chart of accounts and published, so the statements work as soon as the demo opens. */
    private function reportDefinitions(): void
    {
        if (ReportDefinition::query()->exists()) {
            return;
        }

        $this->as($this->manager, function () {
            $created = app(ReportDefaultsService::class)->apply()['created'];
            foreach ($created as $row) {
                $definition = ReportDefinition::query()->findOrFail($row['id']);
                app(ReportDefinitionService::class)->publish($definition, now(config('app.timezone'))->format('Y').'-01-01');
            }
        });
    }

    /** The posting rule of the year-end closing, once the tenant has a retained earnings account mapped. */
    private function yearEndRule(): void
    {
        $setup = app(YearEndSetupService::class);
        if ($setup->status()['retained_earnings']['mapped']) {
            $this->as($this->manager, fn () => $setup->applyDefaults(now(config('app.timezone'))->format('Y').'-01-01'));
        }
    }

    private function checklist(): void
    {
        if (ClosingChecklistTemplate::query()->exists()) {
            return;
        }

        $this->as($this->manager, function () {
            foreach (self::CHECKLIST as $position => [$code, $title, $description]) {
                app(ChecklistService::class)->createTemplate(['code' => $code, 'title' => $title, 'description' => $description, 'required' => $position === 0, 'sort_order' => ($position + 1) * 10]);
            }
        });
    }

    /** One stored run per reconciliation of the active modules, as of today. */
    private function reconciliations(string $asOf): void
    {
        if (ReconciliationRun::query()->exists()) {
            return;
        }

        $this->as($this->accountant, function () use ($asOf) {
            $runs = app(ReconciliationRunService::class);
            foreach (app(ReconciliationEngine::class)->availableTypes() as $type) {
                $runs->run($type, $asOf);
            }
        });
    }

    /**
     * February (soft-closed by DemoAccountingSeeder) is hard-closed through the closing service once its checklist is complete and the readiness
     * allows it, which leaves a pre-close and a hard-close event as closing history. A period that is not ready is left alone: the demo never forces
     * a close. The current period keeps its pending checklist so the closing screen has something to show.
     */
    private function closeFebruary(AccountingPeriod $february): void
    {
        if ($february->status !== AccountingPeriod::SOFT_CLOSED || ClosingEvent::query()->where('event_type', ClosingEvent::HARD_CLOSE)->exists()) {
            return;
        }

        $closing = app(PeriodClosingService::class);
        $checklist = app(ChecklistService::class);
        try {
            $this->as($this->manager, function () use ($february, $checklist) {
                foreach (self::CHECKLIST as [$code]) {
                    $checklist->complete($february, $code, 'Diperiksa dan ditandatangani pada penutupan demo.');
                }
            });
            $this->as($this->accountant, fn () => $closing->preClose($february));
            $this->as($this->manager, fn () => $closing->hardClose($february, 'Penutupan permanen setelah rekonsiliasi dan tinjauan jurnal penyesuaian.'));
        } catch (DomainException) {
            // not ready: the evidence stays, the period stays soft-closed
        }
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
