<?php

namespace App\Policies;

use App\Models\User;

/** Benutzer verwalten nur Admins; sich selbst löschen geht nicht. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->ist_admin;
    }

    public function view(User $user, User $model): bool
    {
        return $user->ist_admin;
    }

    public function create(User $user): bool
    {
        return $user->ist_admin;
    }

    public function update(User $user, User $model): bool
    {
        return $user->ist_admin;
    }

    public function delete(User $user, User $model): bool
    {
        return $user->ist_admin && ! $user->is($model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
