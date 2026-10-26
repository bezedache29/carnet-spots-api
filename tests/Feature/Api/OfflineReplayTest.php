<?php

use App\Http\Requests\StorePointRequest;
use App\Http\Requests\StoreTagRequest;
use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

describe('creating with a client uuid', function () {
    it('stores the uuid sent by the client', function () {
        $user = User::factory()->create();
        $uuid = (string) Str::uuid7();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points', ['uuid' => $uuid, 'latitude' => 45, 'longitude' => 5])
            ->assertCreated()
            ->assertJsonPath('data.uuid', $uuid);

        $this->postJson('/api/v1/tags', ['uuid' => $uuid, 'name' => 'forest'])
            ->assertCreated()
            ->assertJsonPath('data.uuid', $uuid);
    });

    it('generates a uuid when the client sends none', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/points', ['latitude' => 45, 'longitude' => 5]);

        $response->assertCreated();
        expect(Str::isUuid($response->json('data.uuid')))->toBeTrue();
    });

    it('returns the existing point instead of a duplicate when a creation is replayed', function () {
        $user = User::factory()->create();
        $uuid = (string) Str::uuid7();
        Sanctum::actingAs($user);
        $first = $this->postJson('/api/v1/points', ['uuid' => $uuid, 'latitude' => 45, 'longitude' => 5]);

        $replay = $this->postJson('/api/v1/points', ['uuid' => $uuid, 'latitude' => 45, 'longitude' => 5]);

        $replay->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        expect($user->points()->count())->toBe(1);
    });

    it('returns the existing tag instead of a duplicate when a creation is replayed', function () {
        $user = User::factory()->create();
        $uuid = (string) Str::uuid7();
        Sanctum::actingAs($user);
        $first = $this->postJson('/api/v1/tags', ['uuid' => $uuid, 'name' => 'forest']);

        $replay = $this->postJson('/api/v1/tags', ['uuid' => $uuid, 'name' => 'forest']);

        $replay->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        expect($user->tags()->count())->toBe(1);
    });

    it('returns the existing point when a replayed creation no longer validates, e.g. its tag was deleted since', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        $uuid = (string) Str::uuid7();
        Sanctum::actingAs($user);
        $first = $this->postJson('/api/v1/points', ['uuid' => $uuid, 'latitude' => 45, 'longitude' => 5, 'tag_ids' => [$tag->id]]);
        $tag->delete();

        $this->postJson('/api/v1/points', ['uuid' => $uuid, 'latitude' => 45, 'longitude' => 5, 'tag_ids' => [$tag->id]])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
    });

    it('returns the existing tag when a replayed creation no longer validates, e.g. its name was taken since', function () {
        $user = User::factory()->create();
        $uuid = (string) Str::uuid7();
        Sanctum::actingAs($user);
        $first = $this->postJson('/api/v1/tags', ['uuid' => $uuid, 'name' => 'forest']);
        $this->patchJson("/api/v1/tags/{$first->json('data.id')}", ['name' => 'woods'])->assertOk();
        $this->postJson('/api/v1/tags', ['name' => 'forest'])->assertCreated();

        $this->postJson('/api/v1/tags', ['uuid' => $uuid, 'name' => 'forest'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.name', 'woods');
    });

    it('returns the point created by an identical request sent at the same time instead of a 500', function () {
        $user = User::factory()->create();
        $uuid = (string) Str::uuid7();
        // The other request inserts the same uuid between this request's validation and its insert.
        $this->app->afterResolving(StorePointRequest::class, function () use ($user, $uuid): void {
            Point::factory()->for($user, 'owner')->create(['uuid' => $uuid]);
        });
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points', ['uuid' => $uuid, 'latitude' => 45, 'longitude' => 5]);

        $response->assertOk()->assertJsonPath('data.uuid', $uuid);
        expect($user->points()->count())->toBe(1);
    });

    it('returns the tag created by an identical request sent at the same time instead of a 500', function () {
        $user = User::factory()->create();
        $uuid = (string) Str::uuid7();
        $this->app->afterResolving(StoreTagRequest::class, function () use ($user, $uuid): void {
            Tag::factory()->for($user, 'owner')->create(['uuid' => $uuid, 'name' => 'forest']);
        });
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tags', ['uuid' => $uuid, 'name' => 'forest']);

        $response->assertOk()->assertJsonPath('data.uuid', $uuid);
        expect($user->tags()->count())->toBe(1);
    });

    it('returns 404 when the replayed creation was deleted since', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        $point->delete();
        $tag->delete();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points', ['uuid' => $point->uuid, 'latitude' => 45, 'longitude' => 5])->assertNotFound();
        $this->postJson('/api/v1/tags', ['uuid' => $tag->uuid, 'name' => $tag->name])->assertNotFound();
        expect($user->points()->withTrashed()->count())->toBe(1)
            ->and($user->tags()->withTrashed()->count())->toBe(1);
    });

    it('rejects a uuid that belongs to another user', function () {
        $user = User::factory()->create();
        $otherPoint = Point::factory()->create();
        $otherTag = Tag::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points', ['uuid' => $otherPoint->uuid, 'latitude' => 45, 'longitude' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uuid');
        $this->postJson('/api/v1/tags', ['uuid' => $otherTag->uuid, 'name' => 'forest'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uuid');
    });

    it('rejects a uuid that is not a uuid', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/points', ['uuid' => 'not-a-uuid', 'latitude' => 45, 'longitude' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uuid');
        $this->postJson('/api/v1/tags', ['uuid' => ['an', 'array'], 'name' => 'forest'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uuid');
    });
});

describe('updating with base_updated_at', function () {
    it('saves the change when the point did not change since the version the client edited', function () {
        $user = User::factory()->create();
        $this->travelTo(Date::parse('2026-07-14 10:00:00'));
        $point = Point::factory()->for($user, 'owner')->create();
        $this->travelTo(Date::parse('2026-07-16 10:00:00'));
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", [
            'note' => 'From the van',
            'base_updated_at' => '2026-07-14T10:00:00.000000Z',
        ])->assertOk()->assertJsonPath('data.note', 'From the van');
    });

    it('answers 409 with the current point when it changed since the version the client edited', function () {
        $user = User::factory()->create();
        $this->travelTo(Date::parse('2026-07-13 10:00:00'));
        $point = Point::factory()->for($user, 'owner')->create(['note' => 'Original']);
        // Tuesday, at home on the web.
        $this->travelTo(Date::parse('2026-07-14 10:00:00'));
        $point->update(['note' => 'From the web']);
        // Wednesday, the phone replays Monday's offline edit of the 13th version.
        $this->travelTo(Date::parse('2026-07-15 10:00:00'));
        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/points/{$point->id}", [
            'note' => 'From the van',
            'base_updated_at' => '2026-07-13T10:00:00.000000Z',
        ]);

        $response->assertConflict()
            ->assertJsonPath('data.note', 'From the web')
            ->assertJsonPath('message', 'The point was changed since base_updated_at.');
        expect($point->fresh()->note)->toBe('From the web');
    });

    it('answers 409 with the current tag when it changed since the version the client edited', function () {
        $user = User::factory()->create();
        $this->travelTo(Date::parse('2026-07-13 10:00:00'));
        $tag = Tag::factory()->for($user, 'owner')->create(['name' => 'forest']);
        $this->travelTo(Date::parse('2026-07-14 10:00:00'));
        $tag->update(['name' => 'woods']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/tags/{$tag->id}", [
            'name' => 'trees',
            'base_updated_at' => '2026-07-13T10:00:00.000000Z',
        ])->assertConflict()->assertJsonPath('data.name', 'woods');
        expect($tag->fresh()->name)->toBe('woods');
    });

    it('compares instants, not wall-clock times, when base_updated_at has an offset', function () {
        $user = User::factory()->create();
        $this->travelTo(Date::parse('2026-07-14 10:00:00'));
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        // 12:00+02:00 is exactly the stored 10:00 UTC.
        $this->patchJson("/api/v1/points/{$point->id}", [
            'note' => 'From the van',
            'base_updated_at' => '2026-07-14T12:00:00+02:00',
        ])->assertOk();
    });

    it('does not store base_updated_at', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", [
            'note' => 'From the van',
            'base_updated_at' => '2999-01-01T00:00:00Z',
        ])->assertOk();

        expect($point->fresh()->updated_at->year)->not->toBe(2999);
    });

    it('rejects a base_updated_at that is not a date', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['base_updated_at' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('base_updated_at');
    });

    it('returns 404 when updating a deleted point, so the client drops its offline change', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $point->delete();
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['note' => 'From the van'])->assertNotFound();
    });
});

describe('changing only the tags of a point', function () {
    it('bumps the point updated_at so the sync sees the change', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        $this->travelTo(Date::parse('2026-07-14 10:00:00'));
        $point = Point::factory()->for($user, 'owner')->create();
        $this->travelTo(Date::parse('2026-07-14 12:00:00'));
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['tag_ids' => [$tag->id]])->assertOk();

        expect($point->fresh()->updated_at->toDateTimeString())->toBe('2026-07-14 12:00:00');
    });

    it('leaves updated_at alone when the tags are unchanged', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        $this->travelTo(Date::parse('2026-07-14 10:00:00'));
        $point = Point::factory()->for($user, 'owner')->create();
        $point->tags()->attach($tag);
        $this->travelTo(Date::parse('2026-07-14 12:00:00'));
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/points/{$point->id}", ['tag_ids' => [$tag->id]])->assertOk();

        expect($point->fresh()->updated_at->toDateTimeString())->toBe('2026-07-14 10:00:00');
    });
});

describe('deleting', function () {
    it('answers 204 when a point deletion is replayed, without changing its deletion date', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $this->travelTo(Date::parse('2026-07-14 10:00:00'));
        $point->delete();
        $this->travelTo(Date::parse('2026-07-15 10:00:00'));
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/points/{$point->id}")->assertNoContent();

        expect($point->fresh()->deleted_at->toDateTimeString())->toBe('2026-07-14 10:00:00');
    });

    it('still returns 404 for another user deleted tag', function () {
        $tag = Tag::factory()->create();
        $tag->delete();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/v1/tags/{$tag->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    });

    it('answers 204 when a tag deletion is replayed', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        $tag->delete();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertNoContent();
    });

    it('still returns 404 for another user deleted point', function () {
        $point = Point::factory()->create();
        $point->delete();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/v1/points/{$point->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    });

    it('hides deleted points and tags from the other endpoints', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create(['latitude' => 45, 'longitude' => 5]);
        $tag = Tag::factory()->for($user, 'owner')->create();
        $point->delete();
        $tag->delete();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/points')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/points/nearby?lat=45&lng=5')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/points/{$point->id}")->assertNotFound();
        $this->getJson('/api/v1/tags')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/tags/{$tag->id}")->assertNotFound();
    });

    it('detaches a deleted tag from its points', function () {
        $user = User::factory()->create();
        $point = Point::factory()->for($user, 'owner')->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        $point->tags()->attach($tag);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertNoContent();

        $this->assertDatabaseMissing('point_tag', ['point_id' => $point->id, 'tag_id' => $tag->id]);
    });

    it('refuses to attach a deleted tag to a point', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        $tag->delete();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points', ['latitude' => 45, 'longitude' => 5, 'tag_ids' => [$tag->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tag_ids.0');
    });

    it('lets a new tag reuse the name of a deleted one', function () {
        $user = User::factory()->create();
        Tag::factory()->for($user, 'owner')->create(['name' => 'forest'])->delete();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tags', ['name' => 'forest'])->assertCreated();
    });

    it('still refuses two live tags with the same name, even at the database level', function () {
        $user = User::factory()->create();
        Tag::factory()->for($user, 'owner')->create(['name' => 'forest']);

        expect(fn () => Tag::factory()->for($user, 'owner')->create(['name' => 'forest']))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
