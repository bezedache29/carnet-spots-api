<?php

use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;

it('returns every live point and tag on the first sync, without deleted ones', function () {
    $user = User::factory()->create();
    $point = Point::factory()->for($user, 'owner')->create();
    $tag = Tag::factory()->for($user, 'owner')->create();
    $point->tags()->attach($tag);
    Point::factory()->for($user, 'owner')->create()->delete();
    Tag::factory()->for($user, 'owner')->create()->delete();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/sync');

    $response->assertOk()
        ->assertJsonCount(1, 'points')
        ->assertJsonPath('points.0.id', $point->id)
        ->assertJsonPath('points.0.uuid', $point->uuid)
        ->assertJsonPath('points.0.tags.0.id', $tag->id)
        ->assertJsonCount(1, 'tags')
        ->assertJsonPath('tags.0.uuid', $tag->uuid)
        ->assertJsonPath('deleted', ['points' => [], 'tags' => []]);
});

it('returns a server time a few seconds in the past, rounded down to the second', function () {
    $this->travelTo(Date::parse('2026-07-14 10:00:00.750'));
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/sync')
        ->assertOk()
        ->assertJsonPath('server_time', '2026-07-14T09:59:55.000000Z');
});

it('resends a change written just before the previous sync handed out its server time', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    // A point stamped 10:00:00 whose write only became visible after a sync ran at 10:00:01.
    $this->travelTo(Date::parse('2026-07-14 10:00:01'));
    $since = $this->getJson('/api/v1/sync')->json('server_time');
    $this->travelTo(Date::parse('2026-07-14 10:00:00'));
    $point = Point::factory()->for($user, 'owner')->create();
    $this->travelTo(Date::parse('2026-07-14 10:05:00'));

    $this->getJson('/api/v1/sync?since='.urlencode($since))
        ->assertOk()
        ->assertJsonPath('points.0.id', $point->id);
});

it('reports a deleted tag without resending its points, which the client updates itself', function () {
    $user = User::factory()->create();
    $this->travelTo(Date::parse('2026-07-14 10:00:00'));
    $point = Point::factory()->for($user, 'owner')->create();
    $tag = Tag::factory()->for($user, 'owner')->create();
    $point->tags()->attach($tag);
    $this->travelTo(Date::parse('2026-07-14 12:00:00'));
    Sanctum::actingAs($user);
    $this->deleteJson("/api/v1/tags/{$tag->id}")->assertNoContent();

    $this->getJson('/api/v1/sync?since=2026-07-14T11:00:00Z')
        ->assertOk()
        ->assertJsonPath('points', [])
        ->assertJsonPath('deleted.tags.0.id', $tag->id);
    $this->assertDatabaseMissing('point_tag', ['point_id' => $point->id, 'tag_id' => $tag->id]);
});

it('sends a renamed tag without resending its points, which link to it by id', function () {
    $user = User::factory()->create();
    $this->travelTo(Date::parse('2026-07-14 10:00:00'));
    $point = Point::factory()->for($user, 'owner')->create();
    $tag = Tag::factory()->for($user, 'owner')->create(['name' => 'forest']);
    $point->tags()->attach($tag);
    $this->travelTo(Date::parse('2026-07-14 12:00:00'));
    Sanctum::actingAs($user);
    $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'woods'])->assertOk();

    $this->getJson('/api/v1/sync?since=2026-07-14T11:00:00Z')
        ->assertOk()
        ->assertJsonPath('points', [])
        ->assertJsonPath('tags.0.id', $tag->id)
        ->assertJsonPath('tags.0.name', 'woods');
});

it('returns only what changed since the previous sync', function () {
    $user = User::factory()->create();
    $this->travelTo(Date::parse('2026-07-14 10:00:00'));
    $unchanged = Point::factory()->for($user, 'owner')->create();
    $edited = Point::factory()->for($user, 'owner')->create();
    $unchangedTag = Tag::factory()->for($user, 'owner')->create();
    $renamedTag = Tag::factory()->for($user, 'owner')->create();

    $this->travelTo(Date::parse('2026-07-14 12:00:00'));
    $edited->update(['note' => 'Edited']);
    $created = Point::factory()->for($user, 'owner')->create();
    $renamedTag->update(['name' => 'renamed']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/sync?since=2026-07-14T11:00:00Z');

    $response->assertOk()
        ->assertJsonCount(2, 'points')
        ->assertJsonPath('points.0.id', $edited->id)
        ->assertJsonPath('points.1.id', $created->id)
        ->assertJsonCount(1, 'tags')
        ->assertJsonPath('tags.0.id', $renamedTag->id);
});

it('lists the points and tags deleted since the previous sync', function () {
    $user = User::factory()->create();
    $this->travelTo(Date::parse('2026-07-14 10:00:00'));
    $point = Point::factory()->for($user, 'owner')->create();
    $tag = Tag::factory()->for($user, 'owner')->create();
    $deletedBefore = Point::factory()->for($user, 'owner')->create();
    $deletedBefore->delete();

    $this->travelTo(Date::parse('2026-07-14 12:00:00'));
    $point->delete();
    $tag->delete();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/sync?since=2026-07-14T11:00:00Z');

    $response->assertOk()
        ->assertJsonCount(0, 'points')
        ->assertJsonCount(0, 'tags')
        ->assertJsonPath('deleted.points', [['id' => $point->id, 'uuid' => $point->uuid]])
        ->assertJsonPath('deleted.tags', [['id' => $tag->id, 'uuid' => $tag->uuid]]);
});

it('includes a row changed in the same second as since', function () {
    $user = User::factory()->create();
    $this->travelTo(Date::parse('2026-07-14 10:00:00'));
    $point = Point::factory()->for($user, 'owner')->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/sync?since=2026-07-14T10:00:00Z')
        ->assertOk()
        ->assertJsonPath('points.0.id', $point->id);
});

it('converts a since sent with an offset to UTC', function () {
    $user = User::factory()->create();
    $this->travelTo(Date::parse('2026-07-14 10:30:00'));
    $point = Point::factory()->for($user, 'owner')->create();
    Sanctum::actingAs($user);

    // 12:00+02:00 is 10:00 UTC: the point (10:30 UTC) changed after it.
    $this->getJson('/api/v1/sync?since='.urlencode('2026-07-14T12:00:00+02:00'))
        ->assertOk()
        ->assertJsonCount(1, 'points')
        ->assertJsonPath('points.0.id', $point->id);
});

it('never returns another user points, tags or deletions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->travelTo(Date::parse('2026-07-14 10:00:00'));
    Point::factory()->for($otherUser, 'owner')->create();
    Tag::factory()->for($otherUser, 'owner')->create();
    Point::factory()->for($otherUser, 'owner')->create()->delete();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/sync?since=2026-07-14T09:00:00Z')
        ->assertOk()
        ->assertJsonPath('points', [])
        ->assertJsonPath('tags', [])
        ->assertJsonPath('deleted', ['points' => [], 'tags' => []]);
});

it('rejects a since that is not an ISO 8601 date', function (string $since) {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/sync?since='.urlencode($since))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('since');
})->with(['yesterday-ish', 'now', '2026-07-14', '14/07/2026 10:00', '2026-07-14 +10 years', '2026-02-31T10:00:00Z']);

it('accepts the server time it returned as the next since', function () {
    Sanctum::actingAs(User::factory()->create());
    $since = $this->getJson('/api/v1/sync')->json('server_time');

    $this->getJson('/api/v1/sync?since='.urlencode($since))->assertOk();
});

it('returns 401 when not authenticated', function () {
    $this->getJson('/api/v1/sync')->assertUnauthorized();
});

it('returns 403 when the email is not verified', function () {
    Sanctum::actingAs(User::factory()->unverified()->create());

    $this->getJson('/api/v1/sync')->assertForbidden();
});
