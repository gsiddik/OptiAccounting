<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\PostingRule;
use App\Domain\Accounting\Models\PostingRuleLine;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posting rules: which roles an accounting event debits and credits, with the payload component that gives each amount.
 * A rule names roles and component keys only (no account ids, no expressions). DRAFT -> PUBLISHED (immutable, effective
 * window) -> ARCHIVED; a change is a new version, so what a past event was posted with can always be reconstructed.
 */
class PostingRuleService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccountMappingService $mappings,
        private readonly TenantContext $context,
    ) {}

    public function list(?string $eventType = null): Collection
    {
        return PostingRule::query()->with('lines')->when($eventType, fn ($q) => $q->where('event_type', $eventType))
            ->orderBy('event_type')->orderBy('code')->orderByDesc('version')->get();
    }

    public function eventTypes(): Collection
    {
        return DB::table('accounting_event_types')->where('status', 'ACTIVE')->orderBy('sort_order')->get(['code', 'name', 'description', 'components'])
            ->map(fn ($t) => ['code' => $t->code, 'name' => $t->name, 'description' => $t->description, 'components' => json_decode($t->components, true) ?: []]);
    }

    public function create(array $data): PostingRule
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data) {
            $eventType = $this->eventType($data['event_type']);
            if (PostingRule::query()->where('code', $data['code'])->exists()) {
                throw new DomainException('A posting rule with this code exists; create a new version of it instead.', 'POSTING_RULE_CODE_TAKEN', 422);
            }

            $rule = new PostingRule(collect($data)->only(['code', 'event_type', 'name', 'description'])->all());
            $rule->version = 1;
            $rule->status = PostingRule::DRAFT;
            $rule->save();
            $this->writeLines($rule, $data['lines'] ?? [], $eventType['components']);
            $this->audit->record('accounting.posting_rule.created', 'posting_rule', $rule->id, null, ['code' => $rule->code, 'version' => 1, 'event_type' => $rule->event_type, 'lines' => count($data['lines'] ?? [])]);

            return $rule->load('lines');
        }));
    }

    public function update(PostingRule $rule, array $data): PostingRule
    {
        return DB::transaction(function () use ($rule, $data) {
            $rule = $this->lockedDraft($rule);
            $before = $rule->only(['name', 'description']) + ['lines' => $rule->lines()->count()];

            $rule->fill(collect($data)->only(['name', 'description'])->all())->save();
            if (array_key_exists('lines', $data)) {
                $this->writeLines($rule, $data['lines'], $this->eventType($rule->event_type)['components']);
            }
            $this->audit->record('accounting.posting_rule.updated', 'posting_rule', $rule->id, $before, ['name' => $rule->name, 'lines' => $rule->lines()->count()]);

            return $rule->load('lines');
        });
    }

    /** Only a draft that never took effect can be removed. */
    public function delete(PostingRule $rule): void
    {
        DB::transaction(function () use ($rule) {
            $rule = $this->lockedDraft($rule);
            $rule->delete();
            $this->audit->record('accounting.posting_rule.deleted', 'posting_rule', $rule->id, ['code' => $rule->code, 'version' => $rule->version], null);
        });
    }

    /** A new DRAFT version copied from the latest version of the same rule code. */
    public function newVersion(PostingRule $from): PostingRule
    {
        return $this->guarded(fn () => DB::transaction(function () use ($from) {
            DB::table('tenants')->where('id', $this->context->tenantId())->lockForUpdate()->first(); // one version author at a time
            $source = PostingRule::query()->with('lines')->findOrFail($from->id);
            if ($source->status === PostingRule::DRAFT) {
                throw new DomainException('This version is still a draft; edit it directly.', 'POSTING_RULE_IS_DRAFT', 409);
            }
            if (PostingRule::query()->where('code', $source->code)->where('status', PostingRule::DRAFT)->exists()) {
                throw new DomainException('This rule already has a draft version.', 'POSTING_RULE_DRAFT_EXISTS', 409);
            }

            $rule = new PostingRule(['code' => $source->code, 'event_type' => $source->event_type, 'name' => $source->name, 'description' => $source->description]);
            $rule->version = (int) PostingRule::query()->where('code', $source->code)->max('version') + 1;
            $rule->status = PostingRule::DRAFT;
            $rule->save();
            $this->writeLines($rule, $source->lines->map(fn ($l) => $l->only(['side', 'account_role', 'amount_key', 'skip_if_zero', 'description']))->all(), $this->eventType($rule->event_type)['components']);
            $this->audit->record('accounting.posting_rule.version_created', 'posting_rule', $rule->id, ['from_version' => $source->version], ['code' => $rule->code, 'version' => $rule->version]);

            return $rule->load('lines');
        }));
    }

    /**
     * DRAFT -> PUBLISHED from $effectiveFrom. The previous open-ended version of the event type ends the day before; a later
     * version already published ends this one the day before it starts. Windows never overlap (database constraint too).
     */
    public function publish(PostingRule $rule, string $effectiveFrom): PostingRule
    {
        return $this->guarded(fn () => DB::transaction(function () use ($rule, $effectiveFrom) {
            DB::table('tenants')->where('id', $this->context->tenantId())->lockForUpdate()->first(); // serialize window arithmetic per tenant
            $rule = $this->lockedDraft($rule)->load('lines');
            $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();

            $this->assertPublishable($rule);

            $others = PostingRule::query()->where('event_type', $rule->event_type)->whereIn('status', [PostingRule::PUBLISHED, PostingRule::ARCHIVED])
                ->where('id', '!=', $rule->id)->lockForUpdate()->get();

            $previous = $others->filter(fn ($o) => $o->effective_from < $from)->sortByDesc('effective_from')->first();
            if ($previous) {
                if ($previous->effective_to === null) {
                    $previous->effective_to = $from->subDay()->toDateString();
                    $previous->save();
                    $this->audit->record('accounting.posting_rule.window_closed', 'posting_rule', $previous->id, ['effective_to' => null], ['effective_to' => $previous->effective_to->toDateString(), 'superseded_by' => $rule->id]);
                } elseif ($previous->effective_to >= $from) {
                    throw $this->overlap($rule);
                }
            }

            $next = $others->filter(fn ($o) => $o->effective_from >= $from)->sortBy('effective_from')->first();
            if ($next && $next->effective_from->equalTo($from)) {
                throw $this->overlap($rule);
            }

            $rule->effective_from = $from->toDateString();
            $rule->effective_to = $next ? $next->effective_from->subDay()->toDateString() : null;
            $rule->status = PostingRule::PUBLISHED;
            $rule->published_at = now();
            $rule->published_by = $this->context->user()?->id;
            $rule->save();
            $this->audit->record('accounting.posting_rule.published', 'posting_rule', $rule->id, ['status' => PostingRule::DRAFT], [
                'status' => PostingRule::PUBLISHED, 'code' => $rule->code, 'version' => $rule->version, 'effective_from' => $rule->effective_from->toDateString(),
                'effective_to' => $rule->effective_to?->toDateString(),
            ]);

            return $rule->load('lines');
        }));
    }

    /** Retire a published version: it keeps serving dates up to $effectiveTo (default today) and nothing after. */
    public function archive(PostingRule $rule, ?string $effectiveTo = null): PostingRule
    {
        return DB::transaction(function () use ($rule, $effectiveTo) {
            $rule = PostingRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->status !== PostingRule::PUBLISHED) {
                throw new DomainException('Only a published rule can be archived.', 'POSTING_RULE_NOT_PUBLISHED', 409, ['status' => $rule->status]);
            }

            $today = Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
            $to = $effectiveTo ?? max($today, $rule->effective_from->toDateString());
            if ($to < $rule->effective_from->toDateString()) {
                throw new DomainException('The end date cannot be before the rule starts.', 'POSTING_RULE_WINDOW_INVALID', 422);
            }
            if ($rule->effective_to !== null && $to > $rule->effective_to->toDateString()) {
                $to = $rule->effective_to->toDateString(); // archiving never extends a window
            }

            $before = ['status' => $rule->status, 'effective_to' => $rule->effective_to?->toDateString()];
            $rule->status = PostingRule::ARCHIVED;
            $rule->effective_to = $to;
            $rule->save();
            $this->audit->record('accounting.posting_rule.archived', 'posting_rule', $rule->id, $before, ['status' => PostingRule::ARCHIVED, 'effective_to' => $to, 'code' => $rule->code, 'version' => $rule->version]);

            return $rule->load('lines');
        });
    }

    /** The published rule that applies to an event type on a posting date, or a stable error. */
    public function resolve(string $eventType, string $postingDate): PostingRule
    {
        $rule = PostingRule::query()->with('lines')->where('event_type', $eventType)->whereIn('status', [PostingRule::PUBLISHED, PostingRule::ARCHIVED])
            ->whereDate('effective_from', '<=', $postingDate)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $postingDate))
            ->first();

        return $rule ?? throw new DomainException("No posting rule applies to {$eventType} on {$postingDate}.", 'POSTING_RULE_NOT_FOUND', 422, ['event_type' => $eventType, 'posting_date' => $postingDate]);
    }

    /**
     * Rule + payload -> journal lines (account ids resolved through the mappings). Used by the posting engine and by the
     * simulation, so a preview is exactly what posting would do. Amounts are decimal strings; zero lines may be skipped.
     *
     * Source documents may refine the account resolution without touching the rule (the rule still decides sides, roles and
     * amounts); both refinements are explicit data in the payload and therefore part of the stored posting snapshot:
     *  - `role_accounts`: {ROLE: account_id} the account this document names for a role (a vendor's payable override, the GL
     *    account of the cash/bank account chosen on a payment);
     *  - `distribution`: {amount_key: [{amount, account_id?|account_role?, description?, cost_center_id?}]} spreads one rule line
     *    over several destinations (invoice lines classified to different expense accounts); the parts must add up to the component.
     * Resolution order for a line: the part's account, the document's role account, the tenant mapping (DOCUMENT-bound roles have no mapping).
     *
     * @param  array<string,mixed>  $payload
     * @param  array{branch_id?:?string,business_unit_id?:?string,cost_center_id?:?string}  $dimensions
     * @return array{lines: list<array<string,mixed>>, trace: list<array<string,mixed>>}
     */
    public function build(PostingRule $rule, array $payload, array $dimensions, int $scale): array
    {
        $branch = $dimensions['branch_id'] ?? null;
        $unit = $dimensions['business_unit_id'] ?? null;
        $roleAccounts = (array) ($payload['role_accounts'] ?? []);
        $distribution = (array) ($payload['distribution'] ?? []);
        $lines = [];
        $trace = [];

        foreach ($rule->lines as $line) {
            if (! array_key_exists($line->amount_key, $payload)) {
                throw new DomainException("The event does not carry the component {$line->amount_key}.", 'EVENT_COMPONENT_MISSING', 422, ['component' => $line->amount_key]);
            }
            $amount = Money::parse($payload[$line->amount_key], $scale, $line->amount_key);
            if ($amount->isZero() && $line->skip_if_zero) {
                continue;
            }

            $parts = $distribution[$line->amount_key] ?? null;
            $distributed = is_array($parts) && $parts !== [];
            $parts = $distributed ? array_values($parts) : [['amount' => Money::str($amount)]];
            $spread = Money::sum(array_map(fn ($p) => Money::parse($p['amount'] ?? null, $scale, "{$line->amount_key} distribution"), $parts));
            if (! $spread->isEqualTo($amount)) {
                throw new DomainException("The distribution of {$line->amount_key} does not add up to the component.", 'EVENT_DISTRIBUTION_MISMATCH', 422, ['component' => $line->amount_key, 'distributed' => Money::str($spread), 'amount' => Money::str($amount)]);
            }

            $side = $line->side === 'DEBIT' ? 'debit' : 'credit';
            foreach ($parts as $part) {
                $partAmount = Money::parse($part['amount'] ?? null, $scale, "{$line->amount_key} distribution");
                if ($distributed && $partAmount->isZero()) {
                    continue; // a distribution part of zero adds nothing; an undistributed zero line is the rule's business (skip_if_zero)
                }
                $role = (string) ($part['account_role'] ?? $line->account_role);
                $resolved = $this->resolveAccount($role, $part['account_id'] ?? null, $roleAccounts, $branch, $unit);
                $lines[] = [
                    'account_id' => $resolved['account']->id, $side => Money::str($partAmount), 'description' => $part['description'] ?? $line->description,
                    'branch_id' => $branch, 'business_unit_id' => $unit, 'cost_center_id' => $part['cost_center_id'] ?? $dimensions['cost_center_id'] ?? null,
                ];
                $trace[] = [
                    'line' => $line->line_number, 'side' => $line->side, 'account_role' => $role, 'amount_key' => $line->amount_key, 'amount' => Money::str($partAmount),
                    'account_id' => $resolved['account']->id, 'account_code' => $resolved['account']->code, 'account_name' => $resolved['account']->name,
                    'mapping_id' => $resolved['mapping_id'], 'mapping_scope' => $resolved['specificity'],
                ];
            }
        }

        return ['lines' => $lines, 'trace' => $trace];
    }

    /**
     * @param  array<string,string>  $roleAccounts
     * @return array{account: Account, mapping_id: ?string, specificity: string}
     */
    private function resolveAccount(string $role, ?string $accountId, array $roleAccounts, ?string $branch, ?string $unit): array
    {
        $named = $accountId ?? ($roleAccounts[$role] ?? null);
        if ($named !== null) {
            $account = Account::query()->find($named);
            if (! $account || $account->status !== 'ACTIVE' || ! $account->is_postable) {
                throw new DomainException("The account named by the document for the role {$role} is not an active posting account.", 'ACCOUNT_MAPPING_INVALID', 422, ['account_role' => $role]);
            }

            return ['account' => $account, 'mapping_id' => null, 'specificity' => $accountId !== null ? 'DOCUMENT_LINE' : 'DOCUMENT'];
        }

        if (DB::table('account_roles')->where('code', $role)->value('binding') === 'DOCUMENT') {
            throw new DomainException("The document must name the account for the role {$role}.", 'EVENT_ACCOUNT_MISSING', 422, ['account_role' => $role]);
        }

        return $this->mappings->resolve($role, $branch, $unit);
    }

    /** Dry run of a rule against a sample payload: the journal it would build, whether it balances, nothing stored. */
    public function simulate(PostingRule $rule, array $payload, array $dimensions, int $scale): array
    {
        $rule->loadMissing('lines');
        $built = $this->build($rule, $payload, $dimensions, $scale);
        $debit = Money::sum(array_map(fn ($l) => $l['debit'] ?? '0', $built['lines']));
        $credit = Money::sum(array_map(fn ($l) => $l['credit'] ?? '0', $built['lines']));

        return [
            'rule' => ['id' => $rule->id, 'code' => $rule->code, 'version' => $rule->version, 'status' => $rule->status],
            'lines' => $built['trace'], 'total_debit' => Money::str($debit), 'total_credit' => Money::str($credit), 'balanced' => $debit->isEqualTo($credit) && $debit->isPositive(),
        ];
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @return array{code:string,components:list<string>} */
    private function eventType(string $code): array
    {
        $type = DB::table('accounting_event_types')->where('code', $code)->where('status', 'ACTIVE')->first()
            ?? throw new DomainException('Unknown accounting event type.', 'EVENT_TYPE_UNKNOWN', 422, ['event_type' => $code]);

        return ['code' => $type->code, 'components' => json_decode($type->components, true) ?: []];
    }

    private function lockedDraft(PostingRule $rule): PostingRule
    {
        $rule = PostingRule::query()->lockForUpdate()->findOrFail($rule->id);
        if ($rule->status !== PostingRule::DRAFT) {
            throw new DomainException('A published posting rule cannot change; create a new version.', 'POSTING_RULE_IMMUTABLE', 409, ['status' => $rule->status]);
        }

        return $rule;
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeLines(PostingRule $rule, array $lines, array $components): void
    {
        $roles = DB::table('account_roles')->where('status', 'ACTIVE')->pluck('code')->all();
        PostingRuleLine::query()->where('posting_rule_id', $rule->id)->delete();

        foreach (array_values($lines) as $i => $data) {
            if (! in_array($data['account_role'] ?? null, $roles, true)) {
                throw new DomainException('Unknown account role on line '.($i + 1).'.', 'ACCOUNT_ROLE_UNKNOWN', 422, ['line' => $i + 1]);
            }
            if (! in_array($data['amount_key'] ?? null, $components, true)) {
                throw new DomainException('Line '.($i + 1).' uses a component this event type does not carry.', 'POSTING_RULE_COMPONENT_INVALID', 422, ['line' => $i + 1, 'allowed' => $components]);
            }
            $line = new PostingRuleLine(['side' => $data['side'], 'account_role' => $data['account_role'], 'amount_key' => $data['amount_key'],
                'skip_if_zero' => (bool) ($data['skip_if_zero'] ?? true), 'description' => $data['description'] ?? null]);
            $line->posting_rule_id = $rule->id;
            $line->line_number = $i + 1;
            $line->save();
        }
    }

    private function assertPublishable(PostingRule $rule): void
    {
        $sides = $rule->lines->pluck('side')->unique()->all();
        if (! in_array('DEBIT', $sides, true) || ! in_array('CREDIT', $sides, true)) {
            throw new DomainException('A posting rule needs at least one debit line and one credit line.', 'POSTING_RULE_INCOMPLETE', 422);
        }

        $roles = DB::table('account_roles')->whereIn('code', $rule->lines->pluck('account_role')->unique()->all())->get()->keyBy('code');
        foreach ($roles as $role) {
            $restricted = json_decode($role->restricted_events ?? 'null', true);
            if (is_array($restricted) && ! in_array($rule->event_type, $restricted, true)) {
                throw new DomainException("The role {$role->code} belongs to its subledger and cannot be used by {$rule->event_type} rules.", 'POSTING_RULE_ROLE_RESTRICTED', 422, ['account_role' => $role->code, 'allowed_events' => $restricted]);
            }
        }

        $unmapped = $rule->lines->pluck('account_role')->unique()->filter(function ($role) use ($roles) {
            if (($roles[$role]->binding ?? 'MAPPED') === 'DOCUMENT') {
                return false; // the document names the account
            }
            try {
                $this->mappings->resolve($role);

                return false;
            } catch (DomainException) {
                return true;
            }
        })->values()->all();
        if ($unmapped !== []) {
            throw new DomainException('Map these account roles before publishing: '.implode(', ', $unmapped).'.', 'ACCOUNT_MAPPING_MISSING', 422, ['account_roles' => $unmapped]);
        }
    }

    private function overlap(PostingRule $rule): DomainException
    {
        return new DomainException('Another version of this event type already covers that date.', 'POSTING_RULE_WINDOW_OVERLAP', 409, ['event_type' => $rule->event_type]);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? '';
            if ($state === '23505') {
                throw new DomainException('A posting rule with this code and version exists.', 'POSTING_RULE_CODE_TAKEN', 422);
            }
            if ($state === '23P01') { // exclusion_violation: windows overlap
                throw new DomainException('Another version of this event type already covers that date.', 'POSTING_RULE_WINDOW_OVERLAP', 409);
            }
            throw $e;
        }
    }
}
