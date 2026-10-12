<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/**
 * Warm-tier CDR (6-12 months old). Same columns and original ids as call_detail_records, on the cdr_archive connection.
 * Read-only: rows are written by cdr:tier.
 */
#[ScopedBy([OrganizationScope::class])]
class ArchivedCallDetailRecord extends CallDetailRecord
{
    protected $connection = 'cdr_archive';

    protected $table = 'archived_call_detail_records';
}
