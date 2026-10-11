<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Accounting\Models\Account;
use Illuminate\Support\Collection;

/** The chart of accounts as a tree held in memory (one query, many questions): children, posting descendants and roots of an account. */
final class AccountTree
{
    /** @var array<string,list<Account>> parent id => children, in code order */
    private array $children = [];

    /** @param Collection<string,Account> $accounts keyed by id, ordered by code */
    public function __construct(public readonly Collection $accounts)
    {
        foreach ($accounts as $account) {
            if ($account->parent_id !== null) {
                $this->children[$account->parent_id][] = $account;
            }
        }
    }

    /** @return list<Account> */
    public function children(string $accountId): array
    {
        return $this->children[$accountId] ?? [];
    }

    /** @return list<Account> the roots (accounts without a parent) of one type, in code order */
    public function roots(string $accountType): array
    {
        return $this->accounts->filter(fn (Account $a) => $a->parent_id === null && $a->account_type === $accountType)->values()->all();
    }

    /**
     * Every posting account at or below the given account (a posting account stands for itself, a header for the posting accounts under it).
     *
     * @return list<string> account ids
     */
    public function postingUnder(string $accountId): array
    {
        $out = [];
        $stack = [$accountId];
        $seen = [];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id]) || ! isset($this->accounts[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($this->accounts[$id]->is_postable) {
                $out[] = $id;
            }
            foreach ($this->children[$id] ?? [] as $child) {
                $stack[] = $child->id;
            }
        }

        return $out;
    }
}
