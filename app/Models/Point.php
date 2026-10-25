<?php

namespace App\Models;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Database\Factories\PointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Date;

#[Fillable(['title', 'latitude', 'longitude', 'note', 'status', 'visited_at', 'visibility'])]
class Point extends Model
{
    /** @use HasFactory<PointFactory> */
    use HasFactory;

    public const EARTH_RADIUS_KM = 6371;

    /**
     * Mirrors the column defaults so a freshly created point exposes them without a refresh.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => PointStatus::ATester->value,
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
     * Keep points within $radiusKm of the given position, nearest first, exposing a `distance_km` column.
     *
     * A bounding box on (latitude, longitude) pre-filters rows so the composite index is used,
     * then the Haversine formula computes the exact great-circle distance.
     *
     * The box is the exact one enclosing the circle on a sphere. The longitude filter is dropped
     * when the circle contains a pole or crosses the 180th meridian, where a single range can't cover it.
     */
    #[Scope]
    protected function withinRadius(Builder $query, float $latitude, float $longitude, float $radiusKm): void
    {
        $angularRadius = $radiusKm / self::EARTH_RADIUS_KM;
        $latitudeDelta = rad2deg($angularRadius);
        $cosLatitude = cos(deg2rad($latitude));
        $longitudeRatio = $cosLatitude > 0 ? sin($angularRadius) / $cosLatitude : INF;
        $longitudeDelta = $longitudeRatio < 1 ? rad2deg(asin($longitudeRatio)) : INF;

        $query->select('points.*')
            ->selectRaw(
                '2 * ? * asin(least(1, sqrt(power(sin(radians(latitude - ?) / 2), 2) + cos(radians(?)) * cos(radians(latitude)) * power(sin(radians(longitude - ?) / 2), 2)))) as distance_km',
                [self::EARTH_RADIUS_KM, $latitude, $latitude, $longitude],
            )
            ->whereBetween('latitude', [$latitude - $latitudeDelta, $latitude + $latitudeDelta])
            ->when(
                $longitude - $longitudeDelta >= -180 && $longitude + $longitudeDelta <= 180,
                fn (Builder $query) => $query->whereBetween('longitude', [$longitude - $longitudeDelta, $longitude + $longitudeDelta]),
            )
            ->having('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km');
    }
}
