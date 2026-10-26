<?php

use App\Models\Point;
use App\Models\Tag;
use App\Models\User;

it('has many points', function () {
    $user = User::factory()->create();
    $points = Point::factory()->for($user, 'owner')->count(2)->create();
    $otherPoint = Point::factory()->create();

    expect($user->points)->toHaveCount(2)
        ->and($user->points->pluck('id')->sort()->values()->all())
        ->toBe($points->pluck('id')->sort()->values()->all())
        ->and($user->points->pluck('id'))->not->toContain($otherPoint->id);
});

it('has many tags', function () {
    $user = User::factory()->create();
    $tags = Tag::factory()->for($user, 'owner')->count(2)->create();
    $otherTag = Tag::factory()->create();

    expect($user->tags)->toHaveCount(2)
        ->and($user->tags->pluck('id')->sort()->values()->all())
        ->toBe($tags->pluck('id')->sort()->values()->all())
        ->and($user->tags->pluck('id'))->not->toContain($otherTag->id);
});
