<?php

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
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

    it('returns 403 when the point belongs to another user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $point = Point::factory()->for($otherUser, 'owner')->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/points/{$point->id}")->assertForbidden();
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

    it('returns 403 when the point belongs to another user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $point = Point::factory()->for($otherUser, 'owner')->create(['title' => 'Untouched']);
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/points/{$point->id}", [
            'title' => 'Hacked',
            'latitude' => 45.0,
            'longitude' => 5.0,
        ]);

        $response->assertForbidden();
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

    it('returns 403 when the point belongs to another user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $point = Point::factory()->for($otherUser, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/points/{$point->id}");

        $response->assertForbidden();
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
