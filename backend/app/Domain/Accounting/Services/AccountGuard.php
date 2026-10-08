<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\DomainException;

/**
 * One place that answers "may this account be named by an OA2 master or document?": it exists in the tenant (the tenant scope makes a
 * foreign id look missing), is active and postable, has an allowed type and has (or has not) the control flag the caller needs.
 * Posting re-validates everything; this gives the user the refusal at save time, with the field that is wrong.
 */
class AccountGuard
{
    /**
     * @param  list<string>  $types  allowed account types (empty = any)
     * @param  bool|null  $control  true = must be a control account, false = must not be one, null = either
     */
    public function usable(?string $accountId, array $types = [], ?bool $control = null, string $field = 'account_id', ?int $line = null): Account
    {
        $details = ['field' => $field] + ($line === null ? [] : ['line' => $line]);
        $account = $accountId ? Account::query()->find($accountId) : null;
        if (! $account) {
            throw new DomainException('The account does not exist.', 'ACCOUNT_NOT_FOUND', 422, $details);
        }
        if ($account->status !== Account::ACTIVE) {
            throw new DomainException("Account {$account->code} is inactive.", 'ACCOUNT_INACTIVE', 422, $details + ['account' => $account->code]);
        }
        if (! $account->is_postable) {
            throw new DomainException("Account {$account->code} is a header account and cannot be posted to.", 'ACCOUNT_NOT_POSTABLE', 422, $details + ['account' => $account->code]);
        }
        if ($types !== [] && ! in_array($account->account_type, $types, true)) {
            throw new DomainException("Account {$account->code} is a {$account->account_type} account; expected ".implode(' or ', $types).'.', 'ACCOUNT_TYPE_INVALID', 422, $details + ['account' => $account->code]);
        }
        if ($control === true && ! $account->is_control) {
            throw new DomainException("Account {$account->code} is not a control account.", 'ACCOUNT_NOT_CONTROL', 422, $details + ['account' => $account->code]);
        }
        if ($control === false && $account->is_control) {
            throw new DomainException("Account {$account->code} is a control account; it accepts postings from its subledger only.", 'ACCOUNT_CONTROL_RESTRICTED', 422, $details + ['account' => $account->code]);
        }

        return $account;
    }
}
