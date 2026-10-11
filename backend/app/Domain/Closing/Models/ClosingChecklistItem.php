<?php

namespace App\Domain\Closing\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A manual checklist item of one period. Only people move it (PENDING / COMPLETED / WAIVED); system checks are computed live and never stored here. */
class ClosingChecklistItem extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'PENDING';

    public const COMPLETED = 'COMPLETED';

    public const WAIVED = 'WAIVED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'sort_order' => 'integer', 'completed_at' => 'datetime', 'waived_at' => 'datetime'];
    }
}
