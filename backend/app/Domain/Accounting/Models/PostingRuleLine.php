<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PostingRuleLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['side', 'account_role', 'amount_key', 'skip_if_zero', 'description'];

    protected function casts(): array
    {
        return ['skip_if_zero' => 'boolean', 'line_number' => 'integer'];
    }
}
