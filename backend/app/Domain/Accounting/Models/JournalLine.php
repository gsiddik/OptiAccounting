<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'metadata' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(JournalLineDimension::class);
    }
}
