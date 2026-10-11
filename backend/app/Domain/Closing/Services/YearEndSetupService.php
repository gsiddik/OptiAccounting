<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\AccountMappingService;
use App\Domain\Accounting\Services\OperationalSetupService;
use App\Domain\Shared\DomainException;

/**
 * The posting rule the year-end closing needs, as a ready-made set a tenant applies explicitly (the same mechanism and guarantees as the OA2 to
 * OA4 sets: idempotent, nothing overwritten, editable afterwards by publishing a new version). The accounts of the temporary-account lines are
 * named by the closing run itself (DOCUMENT_ACCOUNT); the retained earnings account is never named here or anywhere in code: it is the account
 * the tenant mapped to the role RETAINED_EARNINGS.
 */
class YearEndSetupService
{
    public const EVENT = 'YEAR_END_CLOSING';

    public const ROLE = 'RETAINED_EARNINGS';

    /** event type => [code, name, lines [side, role, component, description]] */
    public const RULES = [
        self::EVENT => ['YEAR-END-CLOSING', 'Penutupan akhir tahun', [
            ['DEBIT', 'DOCUMENT_ACCOUNT', 'debit_close', 'Penutupan akun bersaldo kredit (pendapatan)'],
            ['CREDIT', 'DOCUMENT_ACCOUNT', 'credit_close', 'Penutupan akun bersaldo debit (beban)'],
            ['CREDIT', self::ROLE, 'profit', 'Laba tahun berjalan ke laba ditahan'],
            ['DEBIT', self::ROLE, 'loss', 'Rugi tahun berjalan ke laba ditahan'],
        ]],
    ];

    public function __construct(private readonly OperationalSetupService $setup, private readonly AccountMappingService $mappings) {}

    /**
     * Whether the closing rule is published and which account the retained earnings role points at.
     *
     * @return array{events:list<array<string,mixed>>,retained_earnings:array{mapped:bool,account:?array{id:string,code:string,name:string},problem:?string},unmapped_roles:list<string>}
     */
    public function status(): array
    {
        ['account' => $account, 'problem' => $problem] = $this->retainedEarnings();

        return [
            'events' => $this->setup->status(self::RULES),
            'retained_earnings' => ['mapped' => $account !== null, 'account' => $account === null ? null : $account->only(['id', 'code', 'name']), 'problem' => $problem],
            'unmapped_roles' => $account === null ? [self::ROLE] : [],
        ];
    }

    /** @return array{created:list<string>,skipped:list<array{event_type:string,reason:string}>} */
    public function applyDefaults(?string $effectiveFrom = null): array
    {
        return $this->setup->applyDefaults($effectiveFrom, self::RULES);
    }

    /**
     * The account the tenant mapped to RETAINED_EARNINGS (default scope), or why there is none. The only source of the account: no code, no prefix.
     *
     * @return array{account:?Account,problem:?string}
     */
    public function retainedEarnings(): array
    {
        try {
            return ['account' => $this->mappings->resolve(self::ROLE)['account'], 'problem' => null];
        } catch (DomainException $e) {
            return ['account' => null, 'problem' => $e->errorCode];
        }
    }
}
