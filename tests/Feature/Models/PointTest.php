<?php

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('belongs to an owner', function () {
    $owner = User::factory()->create();
    $point = Point::factory()->for($owner, 'owner')->create();

    expect($point->owner)->toBeInstanceOf(User::class)
        ->and($point->owner->is($owner))->toBeTrue();
});

it('can be attached to tags', function () {
    $point = Point::factory()->create();
    $tags = Tag::factory()->for($point->owner, 'owner')->count(2)->create();

    $point->tags()->attach($tags);

    expect($point->tags)->toHaveCount(2)
        ->and($point->tags->pluck('id')->sort()->values()->all())
        ->toBe($tags->pluck('id')->sort()->values()->all());
});

it('casts latitude and longitude to decimal strings', function () {
    $point = Point::factory()->create([
        'latitude' => 48.856614,
        'longitude' => 2.3522219,
    ]);

    $point->refresh();

    expect($point->latitude)->toBe('48.8566140')
        ->and($point->longitude)->toBe('2.3522219');
});

it('casts visited_at to a datetime instance', function () {
    $point = Point::factory()->create([
        'visited_at' => '2026-01-15 10:30:00',
    ]);

    $point->refresh();

    expect($point->visited_at)->toBeInstanceOf(Carbon::class)
        ->and($point->visited_at->format('Y-m-d H:i:s'))->toBe('2026-01-15 10:30:00');
});

it('allows visited_at to be null', function () {
    $point = Point::factory()->create(['visited_at' => null]);

    expect($point->visited_at)->toBeNull();
});

it('allows title and note to be null', function () {
    $point = Point::factory()->create(['title' => null, 'note' => null]);

    $point->refresh();

    expect($point->title)->toBeNull()
        ->and($point->note)->toBeNull();
});

it('casts status and visibility to their backed enums', function () {
    $point = Point::factory()->create();

    expect($point->latitude)->not->toBeNull()
        ->and($point->longitude)->not->toBeNull()
        ->and($point->owner_id)->not->toBeNull()
        ->and($point->status)->toBeInstanceOf(PointStatus::class)
        ->and($point->visibility)->toBeInstanceOf(PointVisibility::class);

    $this->assertDatabaseHas('points', ['id' => $point->id]);
});

it('rejects a null latitude at the database level', function () {
    Point::factory()->make(['latitude' => null])->save();
})->throws(QueryException::class);

it('rejects a null longitude at the database level', function () {
    Point::factory()->make(['longitude' => null])->save();
})->throws(QueryException::class);

it('rejects an invalid status value when set on the model', function () {
    Point::factory()->make(['status' => 'not_a_valid_status']);
})->throws(ValueError::class);

it('rejects an invalid visibility value when set on the model', function () {
    Point::factory()->make(['visibility' => 'shared']);
})->throws(ValueError::class);

it('rejects an invalid status value at the database level', function () {
    $owner = User::factory()->create();

    DB::table('points')->insert([
        'owner_id' => $owner->id,
        'latitude' => 45.0,
        'longitude' => 5.0,
        'status' => 'not_a_valid_status',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('rejects an invalid visibility value at the database level', function () {
    $owner = User::factory()->create();

    DB::table('points')->insert([
        'owner_id' => $owner->id,
        'latitude' => 45.0,
        'longitude' => 5.0,
        'visibility' => 'shared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('defaults status to to_test and visibility to private at the database level', function () {
    $owner = User::factory()->create();

    $id = DB::table('points')->insertGetId([
        'owner_id' => $owner->id,
        'latitude' => 45.0,
        'longitude' => 5.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $point = Point::find($id);

    expect($point->status)->toBe(PointStatus::ToTest)
        ->and($point->visibility)->toBe(PointVisibility::Private);
});

it('accepts each valid status value', function (PointStatus $status) {
    $point = Point::factory()->create(['status' => $status]);

    expect($point->fresh()->status)->toBe($status);
})->with([
    'to_test' => PointStatus::ToTest,
    'validated' => PointStatus::Validated,
    'to_avoid' => PointStatus::ToAvoid,
    'already_slept' => PointStatus::AlreadySlept,
]);

it('accepts each valid visibility value', function (PointVisibility $visibility) {
    $point = Point::factory()->create(['visibility' => $visibility]);

    expect($point->fresh()->visibility)->toBe($visibility);
})->with([
    'private' => PointVisibility::Private,
    'public' => PointVisibility::Public,
]);

it('throws when attaching the same tag to a point twice', function () {
    $point = Point::factory()->create();
    $tag = Tag::factory()->for($point->owner, 'owner')->create();

    $point->tags()->attach($tag);
    $point->tags()->attach($tag);
})->throws(QueryException::class);

it('deletes its pivot rows when the point is deleted', function () {
    $point = Point::factory()->create();
    $tag = Tag::factory()->for($point->owner, 'owner')->create();
    $point->tags()->attach($tag);

    $point->delete();

    $this->assertDatabaseMissing('point_tag', ['point_id' => $point->id, 'tag_id' => $tag->id]);
});

it('is deleted when its owner is deleted', function () {
    $owner = User::factory()->create();
    $point = Point::factory()->for($owner, 'owner')->create();

    $owner->delete();

    $this->assertModelMissing($point);
});
