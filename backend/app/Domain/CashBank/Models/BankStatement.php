<?php

namespace App\Domain\CashBank\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the bank says for one bank account on one date: a statement balance and its lines, entered by the user. It is reconciliation
 * evidence only. Server-owned columns (account, currency, status, scope, frozen evidence) are set by BankStatementService.
 */
class BankStatement extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'OPEN';

    public const COMPLETED = 'COMPLETED';

    protected $fillable = ['reference', 'statement_date', 'period_start', 'opening_balance', 'closing_balance', 'notes'];

    protected function casts(): array
    {
        return ['statement_date' => 'date:Y-m-d', 'period_start' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class)->select(['id', 'code', 'name', 'kind', 'bank_name', 'account_number_masked', 'account_id', 'status']);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BankStatementItem::class)->orderBy('line_number');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->select(['id', 'code', 'name']);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class)->select(['id', 'code', 'name']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'name']);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->select(['id', 'name']);
    }
}
