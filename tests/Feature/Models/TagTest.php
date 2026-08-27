<?php

use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;

it('belongs to an owner', function () {
    $owner = User::factory()->create();
    $tag = Tag::factory()->for($owner, 'owner')->create();

    expect($tag->owner)->toBeInstanceOf(User::class)
        ->and($tag->owner->is($owner))->toBeTrue();
});

it('can be attached to points', function () {
    $tag = Tag::factory()->create();
    $points = Point::factory()->for($tag->owner, 'owner')->count(2)->create();

    $tag->points()->attach($points);

    expect($tag->points)->toHaveCount(2)
        ->and($tag->points->pluck('id')->sort()->values()->all())
        ->toBe($points->pluck('id')->sort()->values()->all());
});

it('produces a valid model from the factory', function () {
    $tag = Tag::factory()->create();

    expect($tag->name)->not->toBeEmpty()
        ->and($tag->owner_id)->not->toBeNull();

    $this->assertDatabaseHas('tags', ['id' => $tag->id]);
});

it('allows the same tag name for two different owners', function () {
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();

    Tag::factory()->for($firstOwner, 'owner')->create(['name' => 'plage']);
    $secondTag = Tag::factory()->for($secondOwner, 'owner')->create(['name' => 'plage']);

    expect($secondTag->exists)->toBeTrue();
    $this->assertDatabaseCount('tags', 2);
});

it('rejects a duplicate tag name for the same owner', function () {
    $owner = User::factory()->create();

    Tag::factory()->for($owner, 'owner')->create(['name' => 'plage']);
    Tag::factory()->for($owner, 'owner')->create(['name' => 'plage']);
})->throws(QueryException::class);

it('deletes its pivot rows when the tag is deleted', function () {
    $tag = Tag::factory()->create();
    $point = Point::factory()->for($tag->owner, 'owner')->create();
    $tag->points()->attach($point);

    $tag->delete();

    $this->assertDatabaseMissing('point_tag', ['point_id' => $point->id, 'tag_id' => $tag->id]);
});

it('is deleted when its owner is deleted', function () {
    $owner = User::factory()->create();
    $tag = Tag::factory()->for($owner, 'owner')->create();

    $owner->delete();

    $this->assertModelMissing($tag);
});
