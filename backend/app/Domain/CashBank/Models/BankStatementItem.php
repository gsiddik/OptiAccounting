<?php

namespace App\Domain\CashBank\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a bank statement. Its match state and link to a journal line are set only by BankStatementService. */
class BankStatementItem extends Model
{
    use BelongsToTenant, HasUuids;

    public const UNMATCHED = 'UNMATCHED';

    public const MATCHED = 'MATCHED';

    public const EXCEPTION = 'EXCEPTION';

    protected $fillable = ['item_date', 'description', 'reference', 'amount'];

    protected function casts(): array
    {
        return ['item_date' => 'date:Y-m-d', 'matched_at' => 'datetime'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by')->select(['id', 'name']);
    }
}
