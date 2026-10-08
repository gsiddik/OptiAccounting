<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One per tenant. `status` only changes through AccountingProfileService (activation, first posting). */
class AccountingProfile extends Model
{
    use BelongsToTenant, HasUuids;

    public const CONFIGURING = 'CONFIGURING';

    public const READY = 'READY';

    public const LOCKED = 'LOCKED';

    public const FRAMEWORKS = ['SAK_GENERAL', 'SAK_EP', 'SAK_EMKM', 'CUSTOM'];

    protected $fillable = [
        'framework', 'functional_currency', 'currency_scale',
        'approval_required', 'sod_creator_not_approver', 'sod_creator_not_poster', 'sod_approver_not_poster',
    ];

    protected function casts(): array
    {
        return [
            'currency_scale' => 'integer',
            'approval_required' => 'boolean',
            'sod_creator_not_approver' => 'boolean',
            'sod_creator_not_poster' => 'boolean',
            'sod_approver_not_poster' => 'boolean',
            'cutover_date' => 'date:Y-m-d',
            'activated_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return in_array($this->status, [self::READY, self::LOCKED], true);
    }
}
