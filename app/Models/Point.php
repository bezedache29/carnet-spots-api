<?php

namespace App\Models;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Database\Factories\PointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;

#[Fillable(['uuid', 'title', 'latitude', 'longitude', 'note', 'status', 'visited_at', 'visibility'])]
class Point extends Model
{
    /** @use HasFactory<PointFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const EARTH_RADIUS_KM = 6371;

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
     * The `uuid` column is generated when the client does not send one; `id` stays auto-incremented.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

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

    /**
     * Stored in the app timezone (UTC): a value sent with an offset (e.g. `+02:00`) is converted
     * instead of having its offset dropped.
     */
    protected function visitedAt(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => $value === null
                ? null
                : $this->fromDateTime(Date::parse($value)->setTimezone(config('app.timezone'))),
        );
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
     * A bounding box on latitude/longitude pre-filters rows so the composite
     * `(owner_id, latitude, longitude)` index is used. It is the exact box
     * enclosing the circle on a sphere; the longitude filter is dropped when
     * the circle contains a pole or crosses the 180th meridian, where a
     * single range can't cover it.
     *
     * @param  Builder<Point>  $query
     * @return Builder<Point>
     */
    public function scopeNearby(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        $angularRadius = $radiusKm / self::EARTH_RADIUS_KM;
        $latitudeDelta = rad2deg($angularRadius);
        $containsPole = abs($lat) + $latitudeDelta >= 90;
        $longitudeDelta = $containsPole
            ? INF
            : rad2deg(asin(sin($angularRadius) / cos(deg2rad($lat))));

        return $query
            ->whereBetween('latitude', [$lat - $latitudeDelta, $lat + $latitudeDelta])
            ->when(
                $lng - $longitudeDelta >= -180 && $lng + $longitudeDelta <= 180,
                fn (Builder $query) => $query->whereBetween('longitude', [$lng - $longitudeDelta, $lng + $longitudeDelta]),
            )
            ->select('points.*')
            ->selectRaw(
                '(2 * ? * asin(least(1, sqrt(
                    power(sin(radians((latitude - ?) / 2)), 2)
                    + cos(radians(?)) * cos(radians(latitude)) * power(sin(radians((longitude - ?) / 2)), 2)
                )))) as distance_km',
                [self::EARTH_RADIUS_KM, $lat, $lat, $lng]
            )
            ->having('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km')
            ->orderBy('id');
    }
}
