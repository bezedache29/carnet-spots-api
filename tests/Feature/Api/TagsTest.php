<?php

use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('index', function () {
    it('returns only the authenticated user tags, ordered by name', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $zebra = Tag::factory()->for($user, 'owner')->create(['name' => 'zebra']);
        $alpaga = Tag::factory()->for($user, 'owner')->create(['name' => 'alpaga']);
        Tag::factory()->for($otherUser, 'owner')->create(['name' => 'not-mine']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/tags');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $alpaga->id)
            ->assertJsonPath('data.1.id', $zebra->id)
            ->assertJsonStructure(['data' => [['id', 'name']]]);
    });

    it('returns 401 when not authenticated', function () {
        $this->getJson('/api/v1/tags')->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tags')->assertForbidden();
    });
});

describe('store', function () {
    it('creates a tag owned by the authenticated user', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tags', ['name' => 'plage']);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name']])
            ->assertJsonPath('data.name', 'plage');

        $this->assertDatabaseHas('tags', [
            'name' => 'plage',
            'owner_id' => $user->id,
        ]);
    });

    it('ignores a client-supplied owner_id and forces the authenticated user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tags', [
            'name' => 'plage',
            'owner_id' => $otherUser->id,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('tags', [
            'name' => 'plage',
            'owner_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('tags', [
            'name' => 'plage',
            'owner_id' => $otherUser->id,
        ]);
    });

    it('returns 422 when name is missing', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tags', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('returns 422 for a duplicate tag name for the same owner', function () {
        $user = User::factory()->create();
        Tag::factory()->for($user, 'owner')->create(['name' => 'plage']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tags', ['name' => 'plage']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('tags', 1);
    });

    it('allows two different owners to have a tag with the same name', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Tag::factory()->for($otherUser, 'owner')->create(['name' => 'plage']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tags', ['name' => 'plage']);

        $response->assertCreated();

        $this->assertDatabaseCount('tags', 2);
        $this->assertDatabaseHas('tags', ['name' => 'plage', 'owner_id' => $user->id]);
        $this->assertDatabaseHas('tags', ['name' => 'plage', 'owner_id' => $otherUser->id]);
    });

    it('returns 401 when not authenticated', function () {
        $this->postJson('/api/v1/tags', ['name' => 'plage'])->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tags', ['name' => 'plage'])->assertForbidden();
    });

    it('accepts a name of exactly 50 characters', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tags', ['name' => str_repeat('a', 50)])->assertCreated();
    });

    it('returns 422 for a name longer than 50 characters', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tags', ['name' => str_repeat('a', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'The name field must not be greater than 50 characters.']);
    });
});

describe('show', function () {
    it('returns the tag when it belongs to the authenticated user', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tags/{$tag->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $tag->id)
            ->assertJsonPath('data.name', $tag->name);
    });

    it('returns 404 when the tag belongs to another user, so its existence is not revealed', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $tag = Tag::factory()->for($otherUser, 'owner')->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tags/{$tag->id}")->assertNotFound();
    });

    it('returns 404 when the tag does not exist', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tags/999999')->assertNotFound();
    });

    it('returns 401 when not authenticated', function () {
        $tag = Tag::factory()->create();

        $this->getJson("/api/v1/tags/{$tag->id}")->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tags/{$tag->id}")->assertForbidden();
    });

    it('exposes created_at and updated_at', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create([
            'created_at' => '2026-07-01 08:00:00',
            'updated_at' => '2026-07-02 09:30:00',
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('data.created_at', '2026-07-01T08:00:00.000000Z')
            ->assertJsonPath('data.updated_at', '2026-07-02T09:30:00.000000Z');
    });

    it('returns the same 404 body for another user\'s tag as for a missing one', function () {
        $user = User::factory()->create();
        $foreignTag = Tag::factory()->for(User::factory(), 'owner')->create();
        Sanctum::actingAs($user);

        $foreign = $this->getJson("/api/v1/tags/{$foreignTag->id}")->assertNotFound();
        $missing = $this->getJson('/api/v1/tags/999999')->assertNotFound();

        expect($foreign->json())->toBe(['message' => 'Not found.'])
            ->and($missing->json())->toBe($foreign->json());
    });
});

describe('update', function () {
    it('updates the tag when it belongs to the authenticated user', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create(['name' => 'plage']);
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tags/{$tag->id}", ['name' => 'montagne']);

        $response->assertOk()
            ->assertJsonPath('data.name', 'montagne');

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'montagne']);
    });

    it('allows resaving the tag with its own unchanged name', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create(['name' => 'plage']);
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tags/{$tag->id}", ['name' => 'plage']);

        $response->assertOk()
            ->assertJsonPath('data.name', 'plage');
    });

    it('returns 422 when renaming to a name already used by the same owner', function () {
        $user = User::factory()->create();
        Tag::factory()->for($user, 'owner')->create(['name' => 'montagne']);
        $tag = Tag::factory()->for($user, 'owner')->create(['name' => 'plage']);
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tags/{$tag->id}", ['name' => 'montagne']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'plage']);
    });

    it('returns 422 when name is missing', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tags/{$tag->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('returns 404 when the tag belongs to another user, so its existence is not revealed', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $tag = Tag::factory()->for($otherUser, 'owner')->create(['name' => 'plage']);
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tags/{$tag->id}", ['name' => 'montagne']);

        $response->assertNotFound();
        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'plage']);
    });

    it('returns 401 when not authenticated', function () {
        $tag = Tag::factory()->create();

        $this->putJson("/api/v1/tags/{$tag->id}", ['name' => 'montagne'])->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/tags/{$tag->id}", ['name' => 'montagne'])->assertForbidden();
    });

    it('returns 422 when renaming to a name longer than 50 characters', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create(['name' => 'plage']);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/tags/{$tag->id}", ['name' => str_repeat('a', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'The name field must not be greater than 50 characters.']);

        expect($tag->fresh()->name)->toBe('plage');
    });

    it('returns 404 for another user\'s tag before validating the payload', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for(User::factory(), 'owner')->create(['name' => 'plage']);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/tags/{$tag->id}", ['name' => str_repeat('a', 51)])->assertNotFound();

        expect($tag->fresh()->name)->toBe('plage');
    });
});

describe('destroy', function () {
    it('soft-deletes the tag when it belongs to the authenticated user', function () {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/tags/{$tag->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted($tag);
    });

    it('returns 404 when the tag belongs to another user, so its existence is not revealed', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $tag = Tag::factory()->for($otherUser, 'owner')->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/tags/{$tag->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    });

    it('returns 401 when not authenticated', function () {
        $tag = Tag::factory()->create();

        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        $user = User::factory()->unverified()->create();
        $tag = Tag::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertForbidden();
    });
});
