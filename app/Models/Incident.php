<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Virtual model used to hydrate rows from a union of different incident sources
 * (website log history, monitor API results, project component heartbeats).
 *
 * This model intentionally does not map to a real database table. Queries must
 * be built with `Incident::query()->fromSub($unionQuery, 'incidents')` so that
 * rows are hydrated from the selected subquery projection below.
 *
 * @property string $id synthetic composite id, e.g. "website_log-42"
 * @property int $source_row_id foreign key of the exact evidence row in its own source table
 * @property string $source one of: website | api | component
 * @property string $status one of: healthy | unknown | warning | danger
 * @property string $state one of: active | resolved
 * @property string $subject human-readable subject (website name, api title, component name)
 * @property int $subject_id foreign key of the owning record in its own resource
 * @property int|null $component_id linked application component id, when the check is mapped
 * @property string|null $component_name linked application component name, when the check is mapped
 * @property string|null $summary evidence line describing why the event happened
 * @property string|null $cause_key normalized failure cause for filtering: timeout | dns | http | ssl | assertion | other
 * @property \Illuminate\Support\Carbon $occurred_at when the failure transition occurred
 */
class Incident extends Model
{
    protected $table = 'incidents';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'source_row_id' => 'integer',
        'subject_id' => 'integer',
        'component_id' => 'integer',
    ];
}
