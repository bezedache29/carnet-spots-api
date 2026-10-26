<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Only the owner may touch a tag. Other users get a 404 rather than a 403,
 * so they cannot learn that the tag exists.
 */
class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tag $tag): Response
    {
        return $this->ownedBy($user, $tag);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Tag $tag): Response
    {
        return $this->ownedBy($user, $tag);
    }

    public function delete(User $user, Tag $tag): Response
    {
        return $this->ownedBy($user, $tag);
    }

    private function ownedBy(User $user, Tag $tag): Response
    {
        return $user->id === $tag->owner_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
