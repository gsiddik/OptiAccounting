<?php

namespace App\Domain\Tax\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The tax calculated for one document line (or an expense as a whole), frozen with the document: code, rate, method, base, tax, accounts,
 * dates and counterparty exactly as they were. DRAFT follows the draft document; POSTED is a fact; REVERSED follows the document's reversal.
 */
class TaxTransaction extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'DRAFT';

    public const POSTED = 'POSTED';

    public const REVERSED = 'REVERSED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_recoverable' => 'boolean', 'tax_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }
}
