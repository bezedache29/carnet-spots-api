<?php

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns 401 without a token', function (string $method, string $routeName) {
    $point = Point::factory()->create();

    $this->json($method, route($routeName, $point))->assertUnauthorized();
})->with([
    'index' => ['GET', 'v1.points.index'],
    'store' => ['POST', 'v1.points.store'],
    'show' => ['GET', 'v1.points.show'],
    'update' => ['PATCH', 'v1.points.update'],
    'destroy' => ['DELETE', 'v1.points.destroy'],
]);

describe('index', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('lists only the user\'s points, newest first, with their tags', function () {
        $older = Point::factory()->for($this->user, 'owner')->create(['created_at' => now()->subDay()]);
        $newer = Point::factory()->for($this->user, 'owner')->hasAttached(
            Tag::factory()->for($this->user, 'owner')->state(['name' => 'plage'])
        )->create();
        Point::factory()->create();

        $this->getJson(route('v1.points.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.tags.0.name', 'plage')
            ->assertJsonPath('data.1.id', $older->id);
    });

    it('paginates the results', function () {
        Point::factory()->for($this->user, 'owner')->count(51)->create();

        $this->getJson(route('v1.points.index'))
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('splits points created in the same second across pages without overlap or gap', function () {
        // Latitude grows with the id, so MySQL reads the rows through the (owner_id, latitude, longitude)
        // index in ascending id order: without the id tie-breaker the tied rows would not come back newest first.
        $points = Point::factory()->for($this->user, 'owner')->count(51)
            ->sequence(fn (Sequence $sequence) => ['latitude' => 40 + $sequence->index * 0.01])
            ->create(['created_at' => now()]);

        $firstPage = $this->getJson(route('v1.points.index'))->json('data.*.id');
        $secondPage = $this->getJson(route('v1.points.index', ['page' => 2]))->json('data.*.id');

        expect([...$firstPage, ...$secondPage])->toBe($points->pluck('id')->sortDesc()->values()->all());
    });

    it('returns latitude and longitude as numbers', function () {
        Point::factory()->for($this->user, 'owner')->create(['latitude' => 48.856614, 'longitude' => 2.3522219]);

        $this->getJson(route('v1.points.index'))
            ->assertJsonPath('data.0.latitude', 48.856614)
            ->assertJsonPath('data.0.longitude', 2.3522219);
    });
});

describe('store', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('creates a point owned by the user with its tags', function () {
        $tags = Tag::factory()->for($this->user, 'owner')->count(2)->create();

        $response = $this->postJson(route('v1.points.store'), [
            'title' => 'Parking forêt',
            'latitude' => 48.8566140,
            'longitude' => 2.3522219,
            'note' => 'Calme, plat',
            'status' => 'valide',
            'visited_at' => '2026-07-14 21:00:00',
            'visibility' => 'public',
            'tag_ids' => $tags->pluck('id')->all(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Parking forêt')
            ->assertJsonPath('data.latitude', 48.856614)
            ->assertJsonPath('data.status', 'valide')
            ->assertJsonPath('data.visibility', 'public')
            ->assertJsonCount(2, 'data.tags');

        $point = Point::findOrFail($response->json('data.id'));
        expect($point->owner_id)->toBe($this->user->id)
            ->and($point->status)->toBe(PointStatus::Valide)
            ->and($point->visibility)->toBe(PointVisibility::Public)
            ->and($point->tags->pluck('id')->sort()->values()->all())->toBe($tags->pluck('id')->sort()->values()->all());
    });

    it('applies the default status and visibility when omitted', function () {
        $response = $this->postJson(route('v1.points.store'), ['latitude' => 45, 'longitude' => 5]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'a_tester')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.tags', []);
    });

    it('stores visited_at in UTC when it is sent with an offset', function (string $sentValue) {
        $response = $this->postJson(route('v1.points.store'), [
            'latitude' => 45,
            'longitude' => 5,
            'visited_at' => $sentValue,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.visited_at', '2026-07-14T19:00:00.000000Z');

        $this->assertDatabaseHas('points', [
            'id' => $response->json('data.id'),
            'visited_at' => '2026-07-14 19:00:00',
        ]);
    })->with([
        'Paris summer time offset' => '2026-07-14T21:00:00+02:00',
        'already UTC' => '2026-07-14T19:00:00Z',
    ]);

    it('ignores an owner_id sent in the payload', function () {
        $otherUser = User::factory()->create();

        $response = $this->postJson(route('v1.points.store'), [
            'latitude' => 45,
            'longitude' => 5,
            'owner_id' => $otherUser->id,
        ]);

        expect(Point::findOrFail($response->json('data.id'))->owner_id)->toBe($this->user->id);
    });

    it('returns 422 when latitude and longitude are missing', function () {
        $this->postJson(route('v1.points.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'latitude' => 'The latitude field is required.',
                'longitude' => 'The longitude field is required.',
            ]);
    });

    it('returns 422 for an invalid field', function (string $field, mixed $value, string $message) {
        $payload = array_merge(['latitude' => 45, 'longitude' => 5], [$field => $value]);

        $this->postJson(route('v1.points.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
    })->with([
        'latitude above 90' => ['latitude', 90.1, 'The latitude field must be between -90 and 90.'],
        'latitude below -90' => ['latitude', -90.1, 'The latitude field must be between -90 and 90.'],
        'longitude above 180' => ['longitude', 180.1, 'The longitude field must be between -180 and 180.'],
        'longitude below -180' => ['longitude', -180.1, 'The longitude field must be between -180 and 180.'],
        'non-numeric latitude' => ['latitude', 'north', 'The latitude field must be a number.'],
        'unknown status' => ['status', 'not_a_status', 'The selected status is invalid.'],
        'unknown visibility' => ['visibility', 'shared', 'The selected visibility is invalid.'],
        'invalid visited_at' => ['visited_at', 'yesterday-ish', 'The visited at field must be a valid date.'],
        'title too long' => ['title', str_repeat('a', 256), 'The title field must not be greater than 255 characters.'],
    ]);

    it('returns 422 when tagging with another user\'s tag', function () {
        $foreignTag = Tag::factory()->create();

        $this->postJson(route('v1.points.store'), [
            'latitude' => 45,
            'longitude' => 5,
            'tag_ids' => [$foreignTag->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tag_ids.0' => 'The selected tag_ids.0 is invalid.']);

        $this->assertDatabaseCount('points', 0);
    });

    it('returns 422 when tag_ids is not a list', function (mixed $tagIds) {
        $this->postJson(route('v1.points.store'), [
            'latitude' => 45,
            'longitude' => 5,
            'tag_ids' => $tagIds,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tag_ids' => 'The tag ids field must be an array.']);

        $this->assertDatabaseCount('points', 0);
    })->with([
        'a single id' => 5,
        'null' => null,
        'a string' => 'plage',
    ]);

    it('returns 422 for a duplicated tag id', function () {
        $tag = Tag::factory()->for($this->user, 'owner')->create();

        $this->postJson(route('v1.points.store'), [
            'latitude' => 45,
            'longitude' => 5,
            'tag_ids' => [$tag->id, $tag->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tag_ids.0' => 'The tag_ids.0 field has a duplicate value.']);
    });
});

describe('show', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('returns the user\'s point with its tags', function () {
        $point = Point::factory()->for($this->user, 'owner')->hasAttached(
            Tag::factory()->for($this->user, 'owner')->state(['name' => 'plage'])
        )->create();

        $this->getJson(route('v1.points.show', $point))
            ->assertOk()
            ->assertJsonPath('data.id', $point->id)
            ->assertJsonPath('data.tags.0.name', 'plage')
            ->assertJsonMissingPath('data.distance_km');
    });

    it('returns 404 for another user\'s point, even a public one', function () {
        $point = Point::factory()->create(['visibility' => PointVisibility::Public]);

        $this->getJson(route('v1.points.show', $point))->assertNotFound();
    });

    it('returns 404 for a point that does not exist', function () {
        $this->getJson(route('v1.points.show', 999999))->assertNotFound();
    });
});

describe('update', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('changes only the fields sent', function () {
        $point = Point::factory()->for($this->user, 'owner')->create([
            'title' => 'Parking forêt',
            'note' => 'Calme',
            'status' => PointStatus::ATester,
        ]);

        $this->patchJson(route('v1.points.update', $point), ['status' => 'deja_dormi'])
            ->assertOk()
            ->assertJsonPath('data.status', 'deja_dormi')
            ->assertJsonPath('data.title', 'Parking forêt');

        $point->refresh();
        expect($point->status)->toBe(PointStatus::DejaDormi)
            ->and($point->title)->toBe('Parking forêt')
            ->and($point->note)->toBe('Calme');
    });

    it('converts visited_at sent with an offset to UTC', function () {
        $point = Point::factory()->for($this->user, 'owner')->create();

        $this->patchJson(route('v1.points.update', $point), ['visited_at' => '2026-07-14T21:00:00+02:00'])
            ->assertOk()
            ->assertJsonPath('data.visited_at', '2026-07-14T19:00:00.000000Z');
    });

    it('clears visited_at when sent as null', function () {
        $point = Point::factory()->for($this->user, 'owner')->create(['visited_at' => '2026-07-14 19:00:00']);

        $this->patchJson(route('v1.points.update', $point), ['visited_at' => null])->assertOk();

        expect($point->fresh()->visited_at)->toBeNull();
    });

    it('keeps the tags when tag_ids is not sent', function () {
        $tag = Tag::factory()->for($this->user, 'owner')->create();
        $point = Point::factory()->for($this->user, 'owner')->hasAttached($tag)->create();

        $this->patchJson(route('v1.points.update', $point), ['note' => 'Nouvelle note'])->assertOk();

        expect($point->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
    });

    it('replaces the tags with the ones sent', function () {
        $oldTag = Tag::factory()->for($this->user, 'owner')->create();
        $newTag = Tag::factory()->for($this->user, 'owner')->create();
        $point = Point::factory()->for($this->user, 'owner')->hasAttached($oldTag)->create();

        $this->patchJson(route('v1.points.update', $point), ['tag_ids' => [$newTag->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $newTag->id);
    });

    it('removes every tag when tag_ids is an empty list', function () {
        $point = Point::factory()->for($this->user, 'owner')->hasAttached(Tag::factory()->for($this->user, 'owner'))->create();

        $this->patchJson(route('v1.points.update', $point), ['tag_ids' => []])->assertOk();

        expect($point->tags()->count())->toBe(0);
    });

    it('can be replayed with the same tags without failing', function () {
        $tag = Tag::factory()->for($this->user, 'owner')->create();
        $point = Point::factory()->for($this->user, 'owner')->create();
        $payload = ['status' => 'valide', 'tag_ids' => [$tag->id]];

        $this->patchJson(route('v1.points.update', $point), $payload)->assertOk();
        $this->patchJson(route('v1.points.update', $point), $payload)->assertOk();

        expect($point->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
    });

    it('returns 422 when latitude is sent empty', function () {
        $point = Point::factory()->for($this->user, 'owner')->create();

        $this->patchJson(route('v1.points.update', $point), ['latitude' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude' => 'The latitude field is required.']);
    });

    it('returns 422 for an out-of-range coordinate and keeps the point unchanged', function (string $field, float $value, string $message) {
        $point = Point::factory()->for($this->user, 'owner')->create(['latitude' => 45, 'longitude' => 5]);

        $this->patchJson(route('v1.points.update', $point), [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $point->refresh();
        expect((float) $point->latitude)->toBe(45.0)
            ->and((float) $point->longitude)->toBe(5.0);
    })->with([
        'latitude above 90' => ['latitude', 90.5, 'The latitude field must be between -90 and 90.'],
        'longitude below -180' => ['longitude', -180.5, 'The longitude field must be between -180 and 180.'],
    ]);

    it('returns 422 when tagging with another user\'s tag', function () {
        $point = Point::factory()->for($this->user, 'owner')->create();

        $this->patchJson(route('v1.points.update', $point), ['tag_ids' => [Tag::factory()->create()->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tag_ids.0' => 'The selected tag_ids.0 is invalid.']);

        expect($point->tags()->count())->toBe(0);
    });

    it('returns 404 for another user\'s point, even with an invalid payload', function () {
        $point = Point::factory()->create(['status' => PointStatus::ATester]);

        $this->patchJson(route('v1.points.update', $point), ['status' => 'valide'])->assertNotFound();
        $this->patchJson(route('v1.points.update', $point), ['latitude' => 999])->assertNotFound();

        expect($point->fresh()->status)->toBe(PointStatus::ATester);
    });
});

describe('destroy', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('deletes the point and keeps its tags', function () {
        $tag = Tag::factory()->for($this->user, 'owner')->create();
        $point = Point::factory()->for($this->user, 'owner')->hasAttached($tag)->create();

        $this->deleteJson(route('v1.points.destroy', $point))->assertNoContent();

        $this->assertModelMissing($point);
        $this->assertModelExists($tag);
    });

    it('returns 404 for another user\'s point and keeps it', function () {
        $point = Point::factory()->create();

        $this->deleteJson(route('v1.points.destroy', $point))->assertNotFound();

        $this->assertModelExists($point);
    });
});
