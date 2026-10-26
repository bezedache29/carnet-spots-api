<?php

namespace App\Policies;

use App\Models\Point;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Only the owner may touch a point. Other users get a 404 rather than a 403,
 * so they cannot learn that the point exists.
 */
class PointPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Point $point): Response
    {
        return $this->ownedBy($user, $point);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Point $point): Response
    {
        return $this->ownedBy($user, $point);
    }

    public function delete(User $user, Point $point): Response
    {
        return $this->ownedBy($user, $point);
    }

    private function ownedBy(User $user, Point $point): Response
    {
        return $user->id === $point->owner_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
