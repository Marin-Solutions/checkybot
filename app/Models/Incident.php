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
 * @property string $source one of: website | api | component
 * @property string $status one of: warning | danger
 * @property string $subject human-readable subject (website name, api title, component name)
 * @property int $subject_id foreign key of the owning record in its own resource
 * @property string|null $summary evidence line describing why the event happened
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
        'subject_id' => 'integer',
    ];
}
