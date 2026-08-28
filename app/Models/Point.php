<?php

namespace App\Models;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Database\Factories\PointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'latitude', 'longitude', 'note', 'status', 'visited_at', 'visibility'])]
class Point extends Model
{
    /** @use HasFactory<PointFactory> */
    use HasFactory;

    /**
     * Match the database column defaults so a freshly-created in-memory
     * instance (e.g. right after ->create() without status/visibility in
     * the payload) already has a valid enum value instead of null, which
     * would otherwise crash PointResource on ->status->value.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PointStatus::ToTest->value,
        'visibility' => PointVisibility::Private->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'visited_at' => 'datetime',
            'status' => PointStatus::class,
            'visibility' => PointVisibility::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Scope points to those within `$radiusKm` of `$lat`/`$lng`, adding a
     * `distance_km` column and ordering by distance (closest first, ties
     * broken by id for a stable order across identical calls).
     *
     * Uses the haversine formula (not the spherical law of cosines, which
     * loses precision — and returns NULL, silently dropping the row from
     * the `having` clause below — when the great-circle angle is ~0, e.g.
     * searching from a point's own exact coordinates).
     *
     * @param  Builder<Point>  $query
     * @return Builder<Point>
     */
    public function scopeNearby(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        return $query
            ->select('points.*')
            ->selectRaw(
                '(2 * 6371 * asin(least(1, sqrt(
                    power(sin(radians((latitude - ?) / 2)), 2)
                    + cos(radians(?)) * cos(radians(latitude)) * power(sin(radians((longitude - ?) / 2)), 2)
                )))) as distance_km',
                [$lat, $lat, $lng]
            )
            ->having('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km')
            ->orderBy('id');
    }
}
