<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArCreditNoteLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['description', 'quantity', 'unit_price', 'amount', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->select(['id', 'code', 'name']);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->select(['id', 'code', 'name']);
    }
}
