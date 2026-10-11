<?php

namespace App\Domain\Closing\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One override of a close check's default severity (BLOCK | WARN | OFF). A check without a row follows the code default. */
class ClosingPolicyRule extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['check_code', 'severity'];
}
