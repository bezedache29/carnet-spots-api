<?php

namespace App\Policies;

use App\Models\Point;
use App\Models\User;

class PointPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Point $point): bool
    {
        return $user->id === $point->owner_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Point $point): bool
    {
        return $user->id === $point->owner_id;
    }

    public function delete(User $user, Point $point): bool
    {
        return $user->id === $point->owner_id;
    }
}
