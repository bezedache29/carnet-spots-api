<?php

use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns 401 without a token', function (string $method, string $routeName) {
    $tag = Tag::factory()->create();

    $this->json($method, route($routeName, $tag))->assertUnauthorized();
})->with([
    'index' => ['GET', 'v1.tags.index'],
    'store' => ['POST', 'v1.tags.store'],
    'show' => ['GET', 'v1.tags.show'],
    'update' => ['PATCH', 'v1.tags.update'],
    'destroy' => ['DELETE', 'v1.tags.destroy'],
]);

describe('index', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('lists only the user\'s tags, sorted by name', function () {
        Tag::factory()->for($this->user, 'owner')->create(['name' => 'plage']);
        Tag::factory()->for($this->user, 'owner')->create(['name' => 'forêt']);
        Tag::factory()->create(['name' => 'montagne']);

        $this->getJson(route('v1.tags.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'forêt')
            ->assertJsonPath('data.1.name', 'plage');
    });
});

describe('store', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('creates a tag owned by the user', function () {
        $this->postJson(route('v1.tags.store'), ['name' => 'plage'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'plage');

        $this->assertDatabaseHas('tags', ['name' => 'plage', 'owner_id' => $this->user->id]);
    });

    it('allows a name another user already uses', function () {
        Tag::factory()->create(['name' => 'plage']);

        $this->postJson(route('v1.tags.store'), ['name' => 'plage'])->assertCreated();
    });

    it('returns 422 for a name the user already has', function () {
        Tag::factory()->for($this->user, 'owner')->create(['name' => 'plage']);

        $this->postJson(route('v1.tags.store'), ['name' => 'plage'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'The name has already been taken.']);
    });

    it('returns 422 when the name is invalid', function (mixed $name, string $message) {
        $this->postJson(route('v1.tags.store'), ['name' => $name])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => $message]);
    })->with([
        'missing' => [null, 'The name field is required.'],
        'too long' => [str_repeat('a', 51), 'The name field must not be greater than 50 characters.'],
    ]);
});

describe('show', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('returns the user\'s tag', function () {
        $tag = Tag::factory()->for($this->user, 'owner')->create();

        $this->getJson(route('v1.tags.show', $tag))
            ->assertOk()
            ->assertJsonPath('data.id', $tag->id)
            ->assertJsonPath('data.name', $tag->name);
    });

    it('returns 404 for another user\'s tag', function () {
        $this->getJson(route('v1.tags.show', Tag::factory()->create()))->assertNotFound();
    });
});

describe('update', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('renames the user\'s tag', function () {
        $tag = Tag::factory()->for($this->user, 'owner')->create(['name' => 'plage']);

        $this->patchJson(route('v1.tags.update', $tag), ['name' => 'plage de sable'])
            ->assertOk()
            ->assertJsonPath('data.name', 'plage de sable');

        expect($tag->fresh()->name)->toBe('plage de sable');
    });

    it('accepts the tag\'s own current name', function () {
        $tag = Tag::factory()->for($this->user, 'owner')->create(['name' => 'plage']);

        $this->patchJson(route('v1.tags.update', $tag), ['name' => 'plage'])->assertOk();
    });

    it('returns 422 for a name used by another of the user\'s tags', function () {
        Tag::factory()->for($this->user, 'owner')->create(['name' => 'forêt']);
        $tag = Tag::factory()->for($this->user, 'owner')->create(['name' => 'plage']);

        $this->patchJson(route('v1.tags.update', $tag), ['name' => 'forêt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'The name has already been taken.']);
    });

    it('returns 404 for another user\'s tag, even with an invalid payload', function () {
        $tag = Tag::factory()->create(['name' => 'plage']);

        $this->patchJson(route('v1.tags.update', $tag), ['name' => ''])->assertNotFound();

        expect($tag->fresh()->name)->toBe('plage');
    });
});

describe('destroy', function () {
    beforeEach(fn () => Sanctum::actingAs($this->user));

    it('deletes the tag and detaches it from points without deleting them', function () {
        $tag = Tag::factory()->for($this->user, 'owner')->create();
        $point = Point::factory()->for($this->user, 'owner')->create();
        $point->tags()->attach($tag);

        $this->deleteJson(route('v1.tags.destroy', $tag))->assertNoContent();

        $this->assertModelMissing($tag);
        $this->assertModelExists($point);
        $this->assertDatabaseMissing('point_tag', ['tag_id' => $tag->id]);
    });

    it('returns 404 for another user\'s tag and keeps it', function () {
        $tag = Tag::factory()->create();

        $this->deleteJson(route('v1.tags.destroy', $tag))->assertNotFound();

        $this->assertModelExists($tag);
    });
});
