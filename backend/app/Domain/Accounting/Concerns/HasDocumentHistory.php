<?php

namespace App\Domain\Accounting\Concerns;

use App\Domain\Accounting\Models\DocumentTransition;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Shared bits of an OA2 source document: its status constants, creator and append-only history. The model defines DOCUMENT_TYPE. */
trait HasDocumentHistory
{
    public const DRAFT = 'DRAFT';

    public const SUBMITTED = 'SUBMITTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const POSTED = 'POSTED';

    public const CANCELLED = 'CANCELLED';

    public const REVERSED = 'REVERSED';

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'name']);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(DocumentTransition::class, 'document_id')->where('document_type', static::DOCUMENT_TYPE)->orderBy('occurred_at');
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }
}
