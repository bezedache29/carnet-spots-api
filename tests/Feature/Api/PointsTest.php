<?php

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

describe('index', function () {
    it('returns only the authenticated user points, most recent first', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $older = Point::factory()->for($user, 'owner')->create(['created_at' => now()->subMinute()]);
        $newer = Point::factory()->for($user, 'owner')->create(['created_at' => now()]);
        Point::factory()->for($otherUser, 'owner')->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonStructure(['data' => [[
                'id', 'title', 'latitude', 'longitude', 'note', 'status', 'visited_at', 'visibility', 'tags',
            ]]]);
    });

    it('includes the point tags', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        $point->tags()->attach($tag);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points');

        $response->assertOk()
            ->assertJsonPath('data.0.tags.0.id', $tag->id)
            ->assertJsonPath('data.0.tags.0.name', $tag->name);
    });

    it('returns 401 when not authenticated', function () {
        $this->getJson('/api/v1/points')->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/points')->assertForbidden();
    });

    it('paginates the points 50 per page', function () {
        $user = User::factory()->create();
        Point::factory()->for($user, 'owner')->count(51)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/points')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/points?page=2')->assertOk()->assertJsonCount(1, 'data');
    });

    it('splits points created in the same second across pages without overlap or gap', function () {
        $user = User::factory()->create();
        // Latitude grows with the id, so MySQL reads the rows through the (owner_id, latitude, longitude)
        // index in ascending id order: without the id tie-breaker the tied rows would not come back newest first.
        $points = Point::factory()->for($user, 'owner')->count(51)
            ->sequence(fn (Sequence $sequence) => ['latitude' => 40 + $sequence->index * 0.01])
            ->create(['created_at' => now()]);
        Sanctum::actingAs($user);

        $firstPage = $this->getJson('/api/v1/points')->json('data.*.id');
        $secondPage = $this->getJson('/api/v1/points?page=2')->json('data.*.id');

        expect([...$firstPage, ...$secondPage])->toBe($points->pluck('id')->sortDesc()->values()->all());
    });

    it('exposes created_at and updated_at so clients can compare versions', function () {
        $user = User::factory()->create();
        Point::factory()->for($user, 'owner')->create([
            'created_at' => '2026-07-01 08:00:00',
            'updated_at' => '2026-07-02 09:30:00',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/points')
            ->assertOk()
            ->assertJsonPath('data.0.created_at', '2026-07-01T08:00:00.000000Z')
            ->assertJsonPath('data.0.updated_at', '2026-07-02T09:30:00.000000Z');
    });
});

describe('store', function () {
    it('creates a point owned by the authenticated user', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'title' => 'Aire du Lac',
            'latitude' => 45.1234567,
            'longitude' => 5.7654321,
            'note' => 'Calme et gratuit',
            'status' => PointStatus::ToTest->value,
            'visibility' => PointVisibility::Private->value,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => [
                'id', 'title', 'latitude', 'longitude', 'note', 'status', 'visited_at', 'visibility', 'tags',
            ]])
            ->assertJsonPath('data.title', 'Aire du Lac')
            ->assertJsonPath('data.latitude', 45.1234567)
            ->assertJsonPath('data.longitude', 5.7654321)
            ->assertJsonPath('data.status', PointStatus::ToTest->value)
            ->assertJsonPath('data.visibility', PointVisibility::Private->value);

        expect($response->json('data.latitude'))->toBeFloat();
        expect($response->json('data.longitude'))->toBeFloat();

        $this->assertDatabaseHas('points', [
            'title' => 'Aire du Lac',
            'owner_id' => $user->id,
        ]);
    });

    it('ignores a client-supplied owner_id and forces the authenticated user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'owner_id' => $otherUser->id,
            'latitude' => 45.0,
            'longitude' => 5.0,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('points', ['latitude' => 45.0, 'owner_id' => $user->id]);
        $this->assertDatabaseMissing('points', ['latitude' => 45.0, 'owner_id' => $otherUser->id]);
    });

    it('allows title, note and visited_at to be omitted', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', null)
            ->assertJsonPath('data.note', null)
            ->assertJsonPath('data.visited_at', null);
    });

    it('accepts extreme boundary values for latitude and longitude', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => -90,
            'longitude' => 180,
        ]);

        $response->assertCreated();

        expect($response->json('data.latitude'))->toEqual(-90.0)
            ->and($response->json('data.longitude'))->toEqual(180.0);
    });

    it('returns 422 when latitude is missing', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', ['longitude' => 5.0]);

        $response->assertUnprocessable()->assertJsonValidationErrors('latitude');
    });

    it('returns 422 when longitude is missing', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', ['latitude' => 45.0]);

        $response->assertUnprocessable()->assertJsonValidationErrors('longitude');
    });

    it('returns 422 for an out-of-range latitude', function (float $latitude) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', ['latitude' => $latitude, 'longitude' => 5.0]);

        $response->assertUnprocessable()->assertJsonValidationErrors('latitude');
    })->with([
        'just above 90' => 90.0000001,
        'just below -90' => -90.0000001,
        'far above range' => 1000,
        'far below range' => -1000,
    ]);

    it('returns 422 for an out-of-range longitude', function (float $longitude) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', ['latitude' => 45.0, 'longitude' => $longitude]);

        $response->assertUnprocessable()->assertJsonValidationErrors('longitude');
    })->with([
        'just above 180' => 180.0000001,
        'just below -180' => -180.0000001,
        'far above range' => 1000,
        'far below range' => -1000,
    ]);

    it('returns 422 for an invalid status value', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'status' => 'not_a_valid_status',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('status');
    });

    it('returns 422 for an invalid visibility value', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'visibility' => 'shared',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('visibility');
    });

    it('accepts each valid status value', function (PointStatus $status) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'status' => $status->value,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', $status->value);
    })->with([
        'to_test' => PointStatus::ToTest,
        'validated' => PointStatus::Validated,
        'to_avoid' => PointStatus::ToAvoid,
        'already_slept' => PointStatus::AlreadySlept,
    ]);

    it('accepts each valid visibility value', function (PointVisibility $visibility) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'visibility' => $visibility->value,
        ]);

        $response->assertCreated()->assertJsonPath('data.visibility', $visibility->value);
    })->with([
        'private' => PointVisibility::Private,
        'public' => PointVisibility::Public,
    ]);

    it('attaches tag_ids owned by the authenticated user', function () {
        $user = User::factory()->create();
        $tags = Tag::factory()->for($user, 'owner')->count(2)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'tag_ids' => $tags->pluck('id')->all(),
        ]);

        $response->assertCreated()
            ->assertJsonCount(2, 'data.tags');

        $point = Point::first();
        expect($point->tags->pluck('id')->sort()->values()->all())
            ->toBe($tags->pluck('id')->sort()->values()->all());
    });

    it('returns 422 when a tag_id belongs to another user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignTag = Tag::factory()->for($otherUser, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'tag_ids' => [$foreignTag->id],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('tag_ids.0');

        $this->assertDatabaseCount('points', 0);
    });

    it('returns 401 when not authenticated', function () {
        $this->postJson('/api/v1/points', ['latitude' => 45.0, 'longitude' => 5.0])->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points', ['latitude' => 45.0, 'longitude' => 5.0])->assertForbidden();
    });

    it('stores visited_at in UTC when it is sent with an offset', function (string $sentValue) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
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

    it('accepts a note of exactly 10000 characters', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'note' => str_repeat('a', 10000),
        ])->assertCreated();
    });

    it('returns 422 instead of crashing for a note longer than 10000 characters', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'note' => str_repeat('a', 70000),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['note' => 'The note field must not be greater than 10000 characters.']);

        $this->assertDatabaseCount('points', 0);
    });

    it('silently ignores a tag id sent twice', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'tag_ids' => [$tag->id, $tag->id],
        ]);

        $response->assertCreated()
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tag->id);
        $this->assertDatabaseCount('point_tag', 1);
    });

    it('does not keep a half-created point when attaching its tags fails', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        // Simulate a failure right after the tag rows are written, while still inside the request.
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'insert into `point_tag`')) {
                throw new RuntimeException('Simulated failure while attaching tags');
            }
        });

        $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'tag_ids' => [$tag->id],
        ])->assertServerError();

        $this->assertDatabaseCount('points', 0);
        $this->assertDatabaseCount('point_tag', 0);
    });

    it('stores a date-only visited_at as midnight UTC', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'visited_at' => '2026-07-14',
        ])->assertCreated()
            ->assertJsonPath('data.visited_at', '2026-07-14T00:00:00.000000Z');
    });

    it('accepts a visited_at outside the 1970-2038 range of a MySQL timestamp', function (string $sentValue, string $storedValue) {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', [
            'latitude' => 45.0,
            'longitude' => 5.0,
            'visited_at' => $sentValue,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('points', ['id' => $response->json('data.id'), 'visited_at' => $storedValue]);
    })->with([
        'before 1970' => ['1965-05-01 12:00:00', '1965-05-01 12:00:00'],
        'after 2038' => ['2040-01-01 12:00:00', '2040-01-01 12:00:00'],
        'offset pushing it before 1970' => ['1970-01-01T00:30:00+02:00', '1969-12-31 22:30:00'],
    ]);
});

describe('show', function () {
    it('returns the point when it belongs to the authenticated user', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/points/{$point->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $point->id)
            ->assertJsonPath('data.title', $point->title);
    });

    it('returns 404 when the point belongs to another user, so its existence is not revealed', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $point = Point::factory()->for($otherUser, 'owner')->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/points/{$point->id}")->assertNotFound();
    });

    it('returns 404 when the point does not exist', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/points/999999')->assertNotFound();
    });

    it('returns 401 when not authenticated', function () {
        $point = Point::factory()->create();

        $this->getJson("/api/v1/points/{$point->id}")->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/points/{$point->id}")->assertForbidden();
    });

    it('returns the same 404 body for another user\'s point as for a missing one', function () {
        $user = User::factory()->create();
        $foreignPoint = Point::factory()->for(User::factory(), 'owner')->create();
        Sanctum::actingAs($user);

        $foreign = $this->getJson("/api/v1/points/{$foreignPoint->id}")->assertNotFound();
        $missing = $this->getJson('/api/v1/points/999999')->assertNotFound();

        expect($foreign->json())->toBe(['message' => 'Not found.'])
            ->and($missing->json())->toBe($foreign->json());
    });
});

describe('update', function () {
    it('updates the point when it belongs to the authenticated user', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create(['title' => 'Old title']);
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/points/{$point->id}", [
            'title' => 'New title',
            'latitude' => 46.0,
            'longitude' => 6.0,
        ]);

        $response->assertOk()->assertJsonPath('data.title', 'New title');

        $this->assertDatabaseHas('points', ['id' => $point->id, 'title' => 'New title']);
    });

    it('marks a point as visited via a partial patch without title, latitude or longitude', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create([
            'title' => 'Original title',
            'latitude' => 45.1111111,
            'longitude' => 5.2222222,
            'status' => PointStatus::ToTest,
        ]);
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/points/{$point->id}", [
            'status' => PointStatus::AlreadySlept->value,
            'visited_at' => '2026-01-15 10:00:00',
            'note' => 'Great spot, quiet at night',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', PointStatus::AlreadySlept->value)
            ->assertJsonPath('data.note', 'Great spot, quiet at night')
            ->assertJsonPath('data.title', 'Original title')
            ->assertJsonPath('data.latitude', 45.1111111)
            ->assertJsonPath('data.longitude', 5.2222222);

        $point->refresh();
        expect($point->title)->toBe('Original title')
            ->and((float) $point->latitude)->toBe(45.1111111)
            ->and((float) $point->longitude)->toBe(5.2222222)
            ->and($point->status)->toBe(PointStatus::AlreadySlept);
    });

    it('does not overwrite fields omitted from a partial patch', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create(['note' => 'Keep me']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", [
            'status' => PointStatus::Validated->value,
        ])->assertOk();

        $point->refresh();
        expect($point->note)->toBe('Keep me');
    });

    it('returns 422 for an out-of-range latitude on update', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/points/{$point->id}", ['latitude' => 200]);

        $response->assertUnprocessable()->assertJsonValidationErrors('latitude');
    });

    it('returns 422 for an invalid status value on update', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/points/{$point->id}", ['status' => 'nope']);

        $response->assertUnprocessable()->assertJsonValidationErrors('status');
    });

    it('returns 422 for an invalid visibility value on update', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/points/{$point->id}", ['visibility' => 'nope']);

        $response->assertUnprocessable()->assertJsonValidationErrors('visibility');
    });

    it('attaches tag_ids owned by the authenticated user', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/points/{$point->id}", [
            'tag_ids' => [$tag->id],
        ]);

        $response->assertOk()->assertJsonCount(1, 'data.tags');
        expect($point->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
    });

    it('returns 422 when a tag_id belongs to another user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $foreignTag = Tag::factory()->for($otherUser, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/points/{$point->id}", [
            'tag_ids' => [$foreignTag->id],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('tag_ids.0');
        expect($point->tags()->count())->toBe(0);
    });

    it('replaces the previous tag set rather than accumulating tags', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        [$tagA, $tagB] = Tag::factory()->for($user, 'owner')->count(2)->create();
        $point->tags()->attach($tagA);
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/points/{$point->id}", [
            'tag_ids' => [$tagB->id],
        ]);

        $response->assertOk();
        expect($point->tags()->pluck('tags.id')->all())->toBe([$tagB->id]);
    });

    it('replays the same tag_ids twice without error or duplicates', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $payload = ['tag_ids' => [$tag->id]];

        $this->patchJson("/api/v1/points/{$point->id}", $payload)->assertOk();
        $this->patchJson("/api/v1/points/{$point->id}", $payload)->assertOk();

        expect($point->tags()->count())->toBe(1);
        $this->assertDatabaseCount('point_tag', 1);
    });

    it('returns 404 when the point belongs to another user, so its existence is not revealed', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $point = Point::factory()->for($otherUser, 'owner')->create(['title' => 'Untouched']);
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/points/{$point->id}", [
            'title' => 'Hacked',
            'latitude' => 45.0,
            'longitude' => 5.0,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('points', ['id' => $point->id, 'title' => 'Untouched']);
    });

    it('returns 401 when not authenticated', function () {
        $point = Point::factory()->create();

        $this->patchJson("/api/v1/points/{$point->id}", ['note' => 'nope'])->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['note' => 'nope'])->assertForbidden();
    });

    it('converts visited_at sent with an offset to UTC', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['visited_at' => '2026-07-14T21:00:00+02:00'])
            ->assertOk()
            ->assertJsonPath('data.visited_at', '2026-07-14T19:00:00.000000Z');
    });

    it('clears visited_at when sent as null', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create(['visited_at' => '2026-07-14 19:00:00']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['visited_at' => null])->assertOk();

        expect($point->fresh()->visited_at)->toBeNull();
    });

    it('returns 422 for a note longer than 10000 characters on update', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create(['note' => 'Calme']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['note' => str_repeat('a', 10001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note' => 'The note field must not be greater than 10000 characters.']);

        expect($point->fresh()->note)->toBe('Calme');
    });

    it('silently ignores a tag id sent twice on update', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['tag_ids' => [$tag->id, $tag->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.tags');

        $this->assertDatabaseCount('point_tag', 1);
    });

    it('returns 404 for another user\'s point before validating the payload', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for(User::factory(), 'owner')->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['latitude' => 999])->assertNotFound();
    });

    it('returns an error and changes nothing when saving the tags fails', function () {
        $user = User::factory()->create();
        $oldTag = Tag::factory()->for($user, 'owner')->create();
        $newTag = Tag::factory()->for($user, 'owner')->create();
        $point = Point::factory()->for($user, 'owner')->hasAttached($oldTag)->create(['title' => 'Avant']);
        Sanctum::actingAs($user);

        // Simulate a failure right after the new tag rows are written, while still inside the request.
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'insert into `point_tag`')) {
                throw new RuntimeException('Simulated failure while attaching tags');
            }
        });

        $this->patchJson("/api/v1/points/{$point->id}", [
            'title' => 'Après',
            'tag_ids' => [$newTag->id],
        ])->assertServerError()
            ->assertJsonStructure(['message']);

        $point->refresh();
        expect($point->title)->toBe('Avant')
            ->and($point->tags()->pluck('tags.id')->all())->toBe([$oldTag->id]);
    });
});

describe('destroy', function () {
    it('deletes the point when it belongs to the authenticated user', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/points/{$point->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('points', ['id' => $point->id]);
    });

    it('returns 404 when the point belongs to another user, so its existence is not revealed', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $point = Point::factory()->for($otherUser, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/points/{$point->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('points', ['id' => $point->id]);
    });

    it('returns 401 when not authenticated', function () {
        $point = Point::factory()->create();

        $this->deleteJson("/api/v1/points/{$point->id}")->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/points/{$point->id}")->assertForbidden();
    });
});
