<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalance extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'DRAFT';

    public const POSTED = 'POSTED';

    public const REVERSED = 'REVERSED';

    public const CANCELLED = 'CANCELLED';

    protected $fillable = ['cutover_date', 'reference', 'description'];

    protected function casts(): array
    {
        return ['cutover_date' => 'date:Y-m-d', 'posted_at' => 'datetime'];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
