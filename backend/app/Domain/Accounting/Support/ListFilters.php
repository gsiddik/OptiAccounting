<?php

namespace App\Domain\Accounting\Support;

use Illuminate\Validation\Rule;

/**
 * Validation rules of the OA2 list filters, defined once: the paginated list and its CSV export validate the same filter, so an
 * export can never reach rows that the list (and the data scope behind it) would not show.
 */
final class ListFilters
{
    private const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'POSTED', 'CANCELLED', 'REVERSED'];

    private const DATE = ['nullable', 'date_format:Y-m-d'];

    private const DIMENSIONS = ['branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid']];

    /** @return array<string,mixed> */
    public static function vendors(): array
    {
        return ['status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])], 'payment_term_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,200']];
    }

    /** @return array<string,mixed> */
    public static function invoices(): array
    {
        return [
            'status' => ['nullable', Rule::in(self::STATUSES)], 'origin' => ['nullable', Rule::in(['INVOICE', 'EXPENSE'])], 'vendor_id' => ['nullable', 'uuid'],
            'document_from' => self::DATE, 'document_to' => self::DATE, 'posting_from' => self::DATE, 'posting_to' => self::DATE, 'due_from' => self::DATE, 'due_to' => self::DATE,
            'payment_status' => ['nullable', Rule::in(['UNPAID', 'PARTIALLY_PAID', 'PAID'])], 'open' => ['nullable', 'boolean'], 'overdue' => ['nullable', 'boolean'],
            'due_within' => ['nullable', 'integer', 'between:1,365'],
            'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ] + self::DIMENSIONS;
    }

    /** @return array<string,mixed> */
    public static function payments(): array
    {
        return [
            'status' => ['nullable', Rule::in(self::STATUSES)], 'vendor_id' => ['nullable', 'uuid'], 'cash_bank_account_id' => ['nullable', 'uuid'],
            'payment_from' => self::DATE, 'payment_to' => self::DATE, 'posting_from' => self::DATE, 'posting_to' => self::DATE,
            'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ] + self::DIMENSIONS;
    }

    /** @return array<string,mixed> */
    public static function expenses(): array
    {
        return [
            'status' => ['nullable', Rule::in(self::STATUSES)], 'settlement' => ['nullable', Rule::in(['PAYABLE', 'DIRECT_PAID'])],
            'vendor_id' => ['nullable', 'uuid'], 'expense_category_id' => ['nullable', 'uuid'], 'cash_bank_account_id' => ['nullable', 'uuid'],
            'expense_from' => self::DATE, 'expense_to' => self::DATE, 'posting_from' => self::DATE, 'posting_to' => self::DATE,
            'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ] + self::DIMENSIONS;
    }

    /** @return array<string,mixed> */
    public static function cashTransactions(): array
    {
        return [
            'status' => ['nullable', Rule::in(['DRAFT', 'POSTED', 'CANCELLED', 'REVERSED'])],
            'cash_bank_account_id' => ['nullable', 'uuid'], 'counter_account_id' => ['nullable', 'uuid'],
            'transaction_from' => self::DATE, 'transaction_to' => self::DATE, 'posting_from' => self::DATE, 'posting_to' => self::DATE,
            'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ] + self::DIMENSIONS;
    }

    /** @return array<string,mixed> */
    public static function accountMovements(): array
    {
        return [
            'from' => self::DATE, 'to' => self::DATE, 'matched' => ['nullable', 'boolean'], 'direction' => ['nullable', Rule::in(['IN', 'OUT'])],
            'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,200'], 'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
