<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One point-in-time capture of a router's resource/health/interface
 * state, written by Monitoring\SnapshotRecorder. The `resource`,
 * `health`, and `interfaces` columns store exactly the array shape
 * RouterResource::toArray(), HealthReading::toArray(), and
 * InterfaceCollection::toArray() already produce (P1-P3) — this model
 * persists that normalized data, it does not reinterpret RouterOS's
 * response itself.
 *
 * Requires this package's "mikrotik-migrations" to have been published
 * and run (see MikrotikServiceProvider::boot()); P4 is opt-in and does
 * not affect P1-P3 consumers who never publish it.
 *
 * @property int $id
 * @property string $connection
 * @property \Illuminate\Support\Carbon $captured_at
 * @property array<string, mixed>|null $resource
 * @property array<string, mixed>|null $health
 * @property array<string, array<string, mixed>>|null $interfaces
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MikrotikSnapshot extends Model
{
    protected $table = 'mikrotik_snapshots';

    protected $fillable = [
        'connection',
        'captured_at',
        'resource',
        'health',
        'interfaces',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'resource'    => 'array',
        'health'      => 'array',
        'interfaces'  => 'array',
    ];

    /**
     * @param Builder<MikrotikSnapshot> $query
     * @return Builder<MikrotikSnapshot>
     */
    public function scopeForConnection(Builder $query, string $name): Builder
    {
        return $query->where('connection', $name);
    }
}
