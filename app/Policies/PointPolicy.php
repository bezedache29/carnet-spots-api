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
    public function view(User $user, Point $point): Response
    {
        return $this->ownedBy($user, $point);
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
        return $point->owner_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
