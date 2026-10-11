<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Traceability: which source document (and event) a journal or a document of ours came from, so a posted entry can always be explained
 * from the source side and a source document can be found in the ledger. One row per (source, source document, event).
 */
class ExternalSourceLink extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['*'];
}
