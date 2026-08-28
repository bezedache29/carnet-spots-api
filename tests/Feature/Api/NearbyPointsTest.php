<?php

use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('nearby', function () {
    it('is not shadowed by the points/{point} route', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0');

        $response->assertOk();
        expect($response->json('data'))->not->toBeNull();
    });

    it('returns points sorted from closest to farthest', function () {
        $user = User::factory()->create();

        $far = Point::factory()->for($user, 'owner')->create(['latitude' => 45.5, 'longitude' => 5.0]);
        $near = Point::factory()->for($user, 'owner')->create(['latitude' => 45.01, 'longitude' => 5.0]);
        $mid = Point::factory()->for($user, 'owner')->create(['latitude' => 45.05, 'longitude' => 5.0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0&radius=100');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $near->id)
            ->assertJsonPath('data.1.id', $mid->id)
            ->assertJsonPath('data.2.id', $far->id);
    });

    it('returns a distance_km consistent with the Haversine formula', function () {
        $user = User::factory()->create();

        $lat = 45.0;
        $lng = 5.0;
        Point::factory()->for($user, 'owner')->create(['latitude' => 45.05, 'longitude' => 5.0]);
        $expected = haversineKm($lat, $lng, 45.05, 5.0);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/points/nearby?lat={$lat}&lng={$lng}&radius=100");

        $response->assertOk();
        $distance = $response->json('data.0.distance_km');

        expect($distance)->toBeFloat();
        expect(abs($distance - $expected))->toBeLessThan(0.01);
    });

    it('returns the point at its own exact coordinates without a NULL distance, guarding against acos() domain overflow', function () {
        // Regression test for the old "spherical law of cosines" formula:
        // searching from a point's own exact coordinates makes the acos()
        // argument round to fractionally above 1 for some latitudes (e.g.
        // -6.919349), which made acos() return NAN, `distance_km` NULL,
        // and the `having` clause silently drop the point from the results.
        $user = User::factory()->create();
        $lat = -6.919349;
        $lng = 5.0;
        $point = Point::factory()->for($user, 'owner')->create(['latitude' => $lat, 'longitude' => $lng]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/points/nearby?lat={$lat}&lng={$lng}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $point->id);

        expect($response->json('data.0.distance_km'))
            ->not->toBeNull()
            ->toBeLessThan(0.01);
    });

    it('returns a distance_km of (near) zero for a point at the exact search coordinates', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create(['latitude' => 45.0, 'longitude' => 5.0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $point->id);

        expect($response->json('data.0.distance_km'))->toBeLessThan(0.01);
    });

    it('includes the point tags via eager loading', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create(['latitude' => 45.01, 'longitude' => 5.0]);
        $tag = Tag::factory()->for($user, 'owner')->create();
        $point->tags()->attach($tag);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0');

        $response->assertOk()
            ->assertJsonPath('data.0.tags.0.id', $tag->id)
            ->assertJsonPath('data.0.tags.0.name', $tag->name);
    });

    it('excludes points outside the default 20km radius when radius is omitted', function () {
        $user = User::factory()->create();

        $inRange = Point::factory()->for($user, 'owner')->create(['latitude' => 45.05, 'longitude' => 5.0]);
        Point::factory()->for($user, 'owner')->create(['latitude' => 45.5, 'longitude' => 5.0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inRange->id);
    });

    it('excludes points outside an explicit radius', function () {
        $user = User::factory()->create();

        $inRange = Point::factory()->for($user, 'owner')->create(['latitude' => 45.01, 'longitude' => 5.0]);
        Point::factory()->for($user, 'owner')->create(['latitude' => 45.05, 'longitude' => 5.0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0&radius=2');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inRange->id);
    });

    it('caps the number of results to the given limit', function () {
        $user = User::factory()->create();

        $first = Point::factory()->for($user, 'owner')->create(['latitude' => 45.01, 'longitude' => 5.0]);
        $second = Point::factory()->for($user, 'owner')->create(['latitude' => 45.02, 'longitude' => 5.0]);
        Point::factory()->for($user, 'owner')->create(['latitude' => 45.03, 'longitude' => 5.0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0&limit=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);
    });

    it('returns every point within radius when limit is omitted, defaulting to 50', function () {
        $user = User::factory()->create();

        $first = Point::factory()->for($user, 'owner')->create(['latitude' => 45.01, 'longitude' => 5.0]);
        $second = Point::factory()->for($user, 'owner')->create(['latitude' => 45.02, 'longitude' => 5.0]);
        $third = Point::factory()->for($user, 'owner')->create(['latitude' => 45.03, 'longitude' => 5.0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.2.id', $third->id);
    });

    it('never returns points owned by another user, even when very close', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Point::factory()->for($otherUser, 'owner')->create(['latitude' => 45.0, 'longitude' => 5.0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0&radius=100');

        $response->assertOk()->assertJsonCount(0, 'data');
    });

    it('returns 422 when lat is missing', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lng=5.0');

        $response->assertUnprocessable()->assertJsonValidationErrors('lat');
    });

    it('returns 422 when lng is missing', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0');

        $response->assertUnprocessable()->assertJsonValidationErrors('lng');
    });

    it('returns 422 for an out-of-range lat', function (float $lat) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/points/nearby?lat={$lat}&lng=5.0");

        $response->assertUnprocessable()->assertJsonValidationErrors('lat');
    })->with([
        'just above 90' => 90.0000001,
        'just below -90' => -90.0000001,
    ]);

    it('returns 422 for an out-of-range lng', function (float $lng) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/points/nearby?lat=45.0&lng={$lng}");

        $response->assertUnprocessable()->assertJsonValidationErrors('lng');
    })->with([
        'just above 180' => 180.0000001,
        'just below -180' => -180.0000001,
    ]);

    it('returns 422 for a non-positive radius', function (mixed $radius) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/points/nearby?lat=45.0&lng=5.0&radius={$radius}");

        $response->assertUnprocessable()->assertJsonValidationErrors('radius');
    })->with([
        'zero' => 0,
        'negative' => -5,
    ]);

    it('returns 422 for a non-numeric radius', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0&radius=abc');

        $response->assertUnprocessable()->assertJsonValidationErrors('radius');
    });

    it('returns 422 when radius exceeds the technical maximum of 20000km', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0&radius=20000.1');

        $response->assertUnprocessable()->assertJsonValidationErrors('radius');
    });

    it('accepts a radius of exactly 20000km', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0&radius=20000');

        $response->assertOk();
    });

    it('has no upper bound on radius, so a nationwide search is possible', function () {
        $user = User::factory()->create();

        // Paris to Marseille is roughly 660km apart — well beyond any
        // reasonable "local" radius, to prove there's no hidden ceiling.
        $farAway = Point::factory()->for($user, 'owner')->create(['latitude' => 43.2965, 'longitude' => 5.3698]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=48.8566&lng=2.3522&radius=1000');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $farAway->id);
    });

    it('returns 422 when limit exceeds the maximum', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0&limit=101');

        $response->assertUnprocessable()->assertJsonValidationErrors('limit');
    });

    it('returns 401 when not authenticated', function () {
        $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0')->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/points/nearby?lat=45.0&lng=5.0')->assertForbidden();
    });
});

describe('standard CRUD responses do not leak distance_km', function () {
    it('does not include distance_km on index', function () {
        $user = User::factory()->create();
        Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points');

        $response->assertOk();
        expect($response->json('data.0'))->not->toHaveKey('distance_km');
    });

    it('does not include distance_km on show', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/points/{$point->id}");

        $response->assertOk();
        expect($response->json('data'))->not->toHaveKey('distance_km');
    });
});
