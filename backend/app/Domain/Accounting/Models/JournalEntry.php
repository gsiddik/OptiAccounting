<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'DRAFT';

    public const SUBMITTED = 'SUBMITTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const POSTED = 'POSTED';

    public const CANCELLED = 'CANCELLED';

    public const MANUAL = 'MANUAL';

    public const OPENING = 'OPENING';

    public const REVERSAL = 'REVERSAL';

    public const SYSTEM = 'SYSTEM';

    /** Allowed moves of the application workflow (the database repeats them as a trigger). */
    public const TRANSITIONS = [
        self::DRAFT => [self::SUBMITTED, self::CANCELLED, self::POSTED],
        self::SUBMITTED => [self::APPROVED, self::REJECTED, self::CANCELLED],
        self::APPROVED => [self::POSTED, self::CANCELLED],
        self::REJECTED => [self::DRAFT, self::CANCELLED],
        self::POSTED => [],
        self::CANCELLED => [],
    ];

    /** Only these are client-writable; status, number, actors, totals and period are set by the services. */
    protected $fillable = ['document_date', 'transaction_date', 'posting_date', 'description', 'reference'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d', 'transaction_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime',
            'posting_snapshot' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'name']);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_number');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(JournalTransition::class)->orderBy('occurred_at');
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }
}
