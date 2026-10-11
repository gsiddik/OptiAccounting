<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportLine;
use App\Domain\Reporting\Support\AccountTree;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Draft report definitions built from the tenant's OWN chart of accounts, never from account codes: the structure is the hierarchy (a header
 * account becomes a subtotal over the accounts below it), the statement an account belongs to follows its `account_type`, and the cash flow
 * category follows the account roles (receivable, payable, inventory, tax, fixed asset, accumulated depreciation, retained earnings) and the
 * group the account sits in. What cannot be derived is left visible: an asset without a role or a classified sibling lands in UNCLASSIFIED, and
 * every guessed category says why in the line's notes. The result is a DRAFT for the owner to review and publish; nothing is published here.
 *
 * Idempotent: a statement type that already has a definition (a draft, or a published one) is skipped and the answer says so, so running it
 * twice never creates a second draft. A revision of an existing definition is a new version, not a rebuild.
 */
class ReportDefaultsService
{
    /** Header levels that become subtotals; deeper headers collapse into one ACCOUNT_GROUP line so the statement stays readable. */
    private const MAX_SECTION_DEPTH = 3;

    /** The cash flow category an account role implies. */
    private const ROLE_CATEGORY = [
        'ACCUMULATED_DEPRECIATION' => 'NON_CASH', 'FIXED_ASSET' => 'INVESTING', 'RETAINED_EARNINGS' => 'FINANCING',
        'ACCOUNTS_RECEIVABLE' => 'OPERATING', 'ACCOUNTS_PAYABLE' => 'OPERATING', 'INVENTORY_ASSET' => 'OPERATING', 'TAX_RECEIVABLE' => 'OPERATING', 'TAX_PAYABLE' => 'OPERATING',
    ];

    private const NAMES = [
        ReportDefinition::BALANCE_SHEET => 'Laporan Posisi Keuangan (Neraca)',
        ReportDefinition::PROFIT_LOSS => 'Laporan Laba Rugi',
        ReportDefinition::CASH_FLOW => 'Laporan Arus Kas (metode tidak langsung)',
    ];

    /** @var list<array<string,mixed>> */
    private array $lines = [];

    /** @var list<array<string,mixed>> */
    private array $maps = [];

    public function __construct(
        private readonly ReportDefinitionService $definitions,
        private readonly ReportDefinitionValidator $validator,
        private readonly ReportQuery $query,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>|null  $types  statement types to build (default: all three)
     * @return array{created:list<array<string,mixed>>,skipped:list<array<string,mixed>>}
     */
    public function apply(?array $types = null): array
    {
        $types = array_values(array_intersect(ReportDefinition::TYPES, $types ?? ReportDefinition::TYPES));

        return DB::transaction(function () use ($types) {
            $tree = new AccountTree($this->query->accounts());
            $created = [];
            $skipped = [];
            foreach ($types as $type) {
                $existing = ReportDefinition::query()->where('statement_type', $type)->orderByRaw("case status when 'DRAFT' then 0 when 'ACTIVE' then 1 else 2 end")->orderByDesc('version')->first();
                if ($existing !== null) {
                    $skipped[] = [
                        'statement_type' => $type, 'definition_id' => $existing->id, 'code' => $existing->isDraft() ? 'REPORT_DRAFT_EXISTS' : 'REPORT_DEFINITION_EXISTS',
                        'message' => $existing->isDraft()
                            ? "A draft {$type} definition already exists; review and publish it, or delete it first."
                            : "A {$type} definition already exists; create a new version of it to revise the layout.",
                    ];

                    continue;
                }

                $this->lines = [];
                $this->maps = [];
                match ($type) {
                    ReportDefinition::BALANCE_SHEET => $this->balanceSheet($tree),
                    ReportDefinition::PROFIT_LOSS => $this->profitLoss($tree),
                    ReportDefinition::CASH_FLOW => $this->cashFlow($tree),
                };
                if (! $this->hasAccountLines()) {
                    $skipped[] = ['statement_type' => $type, 'definition_id' => null, 'code' => 'NO_ACCOUNTS', 'message' => "The chart of accounts has no active posting account for a {$type} statement."];

                    continue;
                }

                $definition = $this->definitions->create(['statement_type' => $type, 'name' => self::NAMES[$type], 'description' => 'Dibangun dari bagan akun tenant (tipe akun, hierarki, peran akun). Tinjau sebelum diterbitkan.']);
                $this->definitions->replaceLines($definition, $this->lines);
                $this->definitions->replaceMappings($definition, $this->maps);
                $created[] = $this->definitions->header($definition->refresh()) + ['validation' => $this->validator->validate($definition)];
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    // ------------------------------------------------------------------------------------------------ balance sheet and profit and loss

    private function balanceSheet(AccountTree $tree): void
    {
        $this->section($tree, 'ASSET', 'TOT_ASSETS', 'Total Aset');
        $liabilities = $this->section($tree, 'LIABILITY', 'TOT_LIABILITIES', 'Total Kewajiban');
        $equity = $this->section($tree, 'EQUITY', 'TOT_EQUITY', 'Total Ekuitas', always: true);
        $this->line('CURRENT_RESULT', 'Laba (rugi) tahun berjalan', ReportLine::SYSTEM, $equity, ['system_key' => 'CURRENT_RESULT']);

        $operands = array_values(array_filter([$liabilities, $equity])); // an asset-only chart has no right-hand side to total
        $this->line('TOT_LIABILITIES_EQUITY', 'Total Kewajiban dan Ekuitas', ReportLine::CALCULATED_TOTAL, null, [
            'operands' => array_map(fn ($code) => ['line' => $code, 'sign' => '+'], $operands), 'emphasis' => true,
        ]);
    }

    private function profitLoss(AccountTree $tree): void
    {
        $revenue = $this->topLevel($tree, 'REVENUE');
        $expense = $this->topLevel($tree, 'EXPENSE');
        if ($revenue === [] && $expense === []) {
            return;
        }
        $operands = [...array_map(fn ($c) => ['line' => $c, 'sign' => '+'], $revenue), ...array_map(fn ($c) => ['line' => $c, 'sign' => '-'], $expense)];
        $this->line('NET_RESULT_CALC', 'Laba (rugi) bersih', ReportLine::CALCULATED_TOTAL, null, ['operands' => $operands, 'emphasis' => true]);
    }

    /**
     * One side of a statement: the subtotal of every root of the type (one root header is its own total; several roots, or a posting root, get a wrapper).
     *
     * @return ?string the code of the total line, or null when the type has no active account
     */
    private function section(AccountTree $tree, string $type, string $wrapperCode, string $wrapperLabel, bool $always = false): ?string
    {
        $roots = array_values(array_filter($tree->roots($type), fn (Account $a) => $this->usable($tree, $a)));
        if (count($roots) === 1 && ! $roots[0]->is_postable) {
            return $this->node($tree, $roots[0], null, 0);
        }
        if ($roots === [] && ! $always) {
            return null;
        }
        $this->line($wrapperCode, $wrapperLabel, ReportLine::SUBTOTAL, null, ['emphasis' => true]);
        foreach ($roots as $root) {
            $this->node($tree, $root, $wrapperCode, 1);
        }

        return $wrapperCode;
    }

    /** @return list<string> the top-level line codes of every usable root of a type (profit and loss lists them as operands) */
    private function topLevel(AccountTree $tree, string $type): array
    {
        $codes = [];
        foreach ($tree->roots($type) as $root) {
            if ($this->usable($tree, $root)) {
                $codes[] = $this->node($tree, $root, null, 0);
            }
        }

        return $codes;
    }

    /** An account line, or for a header a subtotal over its children (collapsed to a group line once the nesting is deep). @return string the line code */
    private function node(AccountTree $tree, Account $account, ?string $parent, int $depth): string
    {
        $side = in_array($account->account_type, ['ASSET', 'EXPENSE'], true) ? 'DEBIT' : 'CREDIT';
        if ($account->is_postable) {
            $code = $this->code('ACC', $account);
            $this->line($code, $account->name, ReportLine::ACCOUNT, $parent, ['balance_side' => $side]);
            $this->maps[] = ['line_code' => $code, 'account_id' => $account->id, 'include_descendants' => false];

            return $code;
        }
        if ($depth >= self::MAX_SECTION_DEPTH) {
            $code = $this->code('GRP', $account);
            $this->line($code, $account->name, ReportLine::ACCOUNT_GROUP, $parent, ['balance_side' => $side]);
            $this->maps[] = ['line_code' => $code, 'account_id' => $account->id, 'include_descendants' => true];

            return $code;
        }

        $code = $this->code('TOT', $account);
        $this->line($code, 'Total '.$account->name, ReportLine::SUBTOTAL, $parent, ['emphasis' => $depth === 0]);
        foreach ($tree->children($account->id) as $child) {
            if ($this->usable($tree, $child)) {
                $this->node($tree, $child, $code, $depth + 1);
            }
        }

        return $code;
    }

    /** An active posting account, or a header with one below it. */
    private function usable(AccountTree $tree, Account $account): bool
    {
        foreach ($tree->postingUnder($account->id) as $id) {
            if ($tree->accounts[$id]->status === Account::ACTIVE) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------------------------------------ cash flow

    private function cashFlow(AccountTree $tree): void
    {
        $cash = array_flip($this->query->cashAccountIds());
        $roles = $this->rolesByAccount();

        $sections = ['OPERATING' => [], 'INVESTING' => [], 'FINANCING' => [], 'UNCLASSIFIED' => []];
        foreach ($tree->accounts as $account) {
            if (! $account->is_postable || $account->status !== Account::ACTIVE || ! in_array($account->account_type, ['ASSET', 'LIABILITY', 'EQUITY'], true) || isset($cash[$account->id])) {
                continue;
            }
            [$category, $why] = $this->category($tree, $account, $cash, $roles);
            $sections[$category === 'NON_CASH' ? 'OPERATING' : $category][] = [$account, $category, $why];
        }

        $operating = 'CF_OPERATING';
        $this->line($operating, 'Arus kas dari aktivitas operasi', ReportLine::SUBTOTAL, null, ['emphasis' => true]);
        $this->line('NET_RESULT', 'Laba (rugi) bersih', ReportLine::SYSTEM, $operating, ['system_key' => 'NET_RESULT']);
        $totals = [$operating];
        foreach (['OPERATING' => ['CF_OPERATING', null], 'INVESTING' => ['CF_INVESTING', 'Arus kas dari aktivitas investasi'], 'FINANCING' => ['CF_FINANCING', 'Arus kas dari aktivitas pendanaan']] as $section => [$code, $label]) {
            if ($section !== 'OPERATING') {
                if ($sections[$section] === []) {
                    continue;
                }
                $this->line($code, $label, ReportLine::SUBTOTAL, null, ['emphasis' => true]);
                $totals[] = $code;
            }
            // non-cash adjustments first, then the other lines of the section
            usort($sections[$section], fn ($a, $b) => ($a[1] === 'NON_CASH' ? 0 : 1) <=> ($b[1] === 'NON_CASH' ? 0 : 1));
            foreach ($sections[$section] as [$account, $category, $why]) {
                $this->cashLine($account, $code, $category, $why);
            }
        }

        $unclassified = 'CF_UNCLASSIFIED';
        $this->line($unclassified, 'Belum diklasifikasi', ReportLine::SUBTOTAL, null, []);
        foreach ($sections['UNCLASSIFIED'] as [$account, $category, $why]) {
            $this->cashLine($account, $unclassified, $category, $why);
        }
        $this->line('UNCLASSIFIED_CASH_FLOW', 'Pergerakan akun yang belum dipetakan', ReportLine::SYSTEM, $unclassified, ['system_key' => 'UNCLASSIFIED_CASH_FLOW']);
        $totals[] = $unclassified;

        $this->line('CF_NET_CHANGE', 'Kenaikan (penurunan) bersih kas dan setara kas', ReportLine::CALCULATED_TOTAL, null, [
            'operands' => array_map(fn ($c) => ['line' => $c, 'sign' => '+'], $totals), 'emphasis' => true,
        ]);
        $this->line('CASH_OPENING', 'Kas dan setara kas awal periode', ReportLine::SYSTEM, null, ['system_key' => 'CASH_OPENING']);
        $this->line('CF_CLOSING_CALC', 'Kas dan setara kas akhir periode (hasil perhitungan)', ReportLine::CALCULATED_TOTAL, null, [
            'operands' => [['line' => 'CASH_OPENING', 'sign' => '+'], ['line' => 'CF_NET_CHANGE', 'sign' => '+']], 'emphasis' => true,
        ]);
        $this->line('CASH_CLOSING', 'Kas dan setara kas akhir periode (menurut buku besar)', ReportLine::SYSTEM, null, ['system_key' => 'CASH_CLOSING']);
    }

    private function cashLine(Account $account, string $parent, string $category, string $why): void
    {
        $code = $this->code('ACC', $account);
        $this->line($code, $account->name, ReportLine::ACCOUNT, $parent, [
            'balance_side' => 'CREDIT', 'cash_flow_category' => $category, 'notes' => $why, // credit minus debit = the effect of the movement on cash
        ]);
        $this->maps[] = ['line_code' => $code, 'account_id' => $account->id, 'include_descendants' => false];
    }

    /**
     * The cash flow category of a non-cash balance sheet account and the reason, in order: its own account role; the roles of the accounts in the same
     * group (when they agree); the account type (equity and liabilities are financing); otherwise UNCLASSIFIED.
     *
     * @param  array<string,int>  $cash  cash account ids (flipped)
     * @param  array<string,list<string>>  $roles  account id => roles
     * @return array{0:string,1:string}
     */
    private function category(AccountTree $tree, Account $account, array $cash, array $roles): array
    {
        foreach ($roles[$account->id] ?? [] as $role) {
            if (isset(self::ROLE_CATEGORY[$role])) {
                return [self::ROLE_CATEGORY[$role], "Dari peran akun {$role}."];
            }
        }

        if ($account->parent_id !== null) {
            $found = [];
            foreach ($tree->children($account->parent_id) as $sibling) {
                if ($sibling->id === $account->id || isset($cash[$sibling->id]) || ! $sibling->is_postable) {
                    continue;
                }
                foreach ($roles[$sibling->id] ?? [] as $role) {
                    if (isset(self::ROLE_CATEGORY[$role]) && self::ROLE_CATEGORY[$role] !== 'NON_CASH') {
                        $found[self::ROLE_CATEGORY[$role]] = $role;
                    }
                }
            }
            if (count($found) === 1) {
                return [array_key_first($found), 'Mengikuti akun sekelompok dengan peran '.reset($found).'.'];
            }
        }

        return match ($account->account_type) {
            'EQUITY', 'LIABILITY' => ['FINANCING', 'Default menurut tipe akun; tidak ada peran atau akun sekelompok yang menentukan.'],
            default => ['UNCLASSIFIED', 'Tidak dapat ditentukan dari peran akun atau akun sekelompok; tentukan kategorinya.'],
        };
    }

    /** @return array<string,list<string>> account id => the roles it is mapped to */
    private function rolesByAccount(): array
    {
        $out = [];
        foreach (DB::table('account_mappings')->where('tenant_id', $this->context->tenantId())->where('status', 'ACTIVE')->get(['account_id', 'account_role']) as $row) {
            $out[$row->account_id][] = $row->account_role;
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ builder

    /** @param array<string,mixed> $extra */
    private function line(string $code, string $label, string $type, ?string $parent, array $extra): void
    {
        $this->lines[] = ['code' => $code, 'label' => $label, 'line_type' => $type, 'parent_code' => $parent] + $extra;
    }

    private function code(string $prefix, Account $account): string
    {
        return $prefix.'_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $account->code);
    }

    private function hasAccountLines(): bool
    {
        return $this->maps !== [];
    }
}
