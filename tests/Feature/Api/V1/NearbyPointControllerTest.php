<?php

use App\Models\Point;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Reference distances from Paris Notre-Dame (48.8530, 2.3499):
 * - 0.01° further north ≈ 1.112 km
 * - Versailles château (48.8049, 2.1204) ≈ 17.7 km
 * - Lyon Bellecour (45.7578, 4.8320) ≈ 392 km
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->paris = ['latitude' => 48.8530, 'longitude' => 2.3499];
});

function nearbyUrl(array $query): string
{
    return route('v1.points.nearby', $query);
}

it('returns 401 without a token', function () {
    $this->getJson(nearbyUrl($this->paris))->assertUnauthorized();
});

describe('search', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('returns the points within the radius, nearest first, with their distance', function () {
        $versailles = Point::factory()->for($this->user, 'owner')->create(['latitude' => 48.8049, 'longitude' => 2.1204]);
        $closeBy = Point::factory()->for($this->user, 'owner')->create(['latitude' => 48.8630, 'longitude' => 2.3499]);
        Point::factory()->for($this->user, 'owner')->create(['latitude' => 45.7578, 'longitude' => 4.8320]);

        $response = $this->getJson(nearbyUrl([...$this->paris, 'radius_km' => 20]));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $closeBy->id)
            ->assertJsonPath('data.1.id', $versailles->id);

        expect($response->json('data.0.distance_km'))->toEqualWithDelta(1.112, 0.01)
            ->and($response->json('data.1.distance_km'))->toEqualWithDelta(17.7, 0.3);
    });

    it('uses a 10 km radius by default', function () {
        $closeBy = Point::factory()->for($this->user, 'owner')->create(['latitude' => 48.8630, 'longitude' => 2.3499]);
        Point::factory()->for($this->user, 'owner')->create(['latitude' => 48.8049, 'longitude' => 2.1204]);

        $this->getJson(nearbyUrl($this->paris))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $closeBy->id);
    });

    it('does not return another user\'s points', function () {
        Point::factory()->create(['latitude' => 48.8530, 'longitude' => 2.3499]);

        $this->getJson(nearbyUrl($this->paris))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('stops at the requested limit, keeping the nearest', function () {
        $nearest = Point::factory()->for($this->user, 'owner')->create(['latitude' => 48.8531, 'longitude' => 2.3499]);
        Point::factory()->for($this->user, 'owner')->count(3)->create(['latitude' => 48.8600, 'longitude' => 2.3499]);

        $this->getJson(nearbyUrl([...$this->paris, 'limit' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $nearest->id);
    });

    it('finds a point on the other side of the 180th meridian', function () {
        $acrossTheLine = Point::factory()->for($this->user, 'owner')->create(['latitude' => 0, 'longitude' => -179.99]);

        $response = $this->getJson(nearbyUrl(['latitude' => 0, 'longitude' => 179.99, 'radius_km' => 5]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $acrossTheLine->id);

        expect($response->json('data.0.distance_km'))->toEqualWithDelta(2.224, 0.01);
    });

    it('finds points on every side of the pole when searching from it', function () {
        $sameMeridian = Point::factory()->for($this->user, 'owner')->create(['latitude' => 89.99, 'longitude' => 0]);
        $oppositeMeridian = Point::factory()->for($this->user, 'owner')->create(['latitude' => 89.99, 'longitude' => 180]);

        $response = $this->getJson(nearbyUrl(['latitude' => 90, 'longitude' => 0, 'radius_km' => 5]));

        $response->assertOk()->assertJsonCount(2, 'data');
        expect($response->json('data.*.id'))->toEqualCanonicalizing([$sameMeridian->id, $oppositeMeridian->id])
            ->and($response->json('data.0.distance_km'))->toEqualWithDelta(1.112, 0.01);
    });

    it('finds a point at the eastern edge of a large radius at high latitude', function () {
        // 199.5 km from (80, 0), at the longitude where the 200 km circle reaches furthest east.
        $edgePoint = Point::factory()->for($this->user, 'owner')->create(['latitude' => 80.160653, 'longitude' => 10.387195]);

        $response = $this->getJson(nearbyUrl(['latitude' => 80, 'longitude' => 0, 'radius_km' => 200]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $edgePoint->id);

        expect($response->json('data.0.distance_km'))->toEqualWithDelta(199.5, 0.01);
    });

    it('returns 422 when latitude and longitude are missing', function () {
        $this->getJson(nearbyUrl([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'latitude' => 'The latitude field is required.',
                'longitude' => 'The longitude field is required.',
            ]);
    });

    it('returns 422 for an invalid parameter', function (string $field, mixed $value, string $message) {
        $this->getJson(nearbyUrl([...$this->paris, $field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
    })->with([
        'latitude out of range' => ['latitude', 91, 'The latitude field must be between -90 and 90.'],
        'longitude out of range' => ['longitude', -181, 'The longitude field must be between -180 and 180.'],
        'zero radius' => ['radius_km', 0, 'The radius km field must be greater than 0.'],
        'radius above maximum' => ['radius_km', 201, 'The radius km field must not be greater than 200.'],
        'limit above maximum' => ['limit', 201, 'The limit field must not be greater than 200.'],
        'limit below 1' => ['limit', 0, 'The limit field must be at least 1.'],
    ]);
});
