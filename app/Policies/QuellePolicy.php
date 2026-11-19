<?php

namespace App\Policies;

use App\Models\Quelle;
use App\Models\User;

/** Quellen (inkl. Zugangsdaten) verwalten nur Admins. */
class QuellePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->ist_admin;
    }

    public function view(User $user, Quelle $quelle): bool
    {
        return $user->ist_admin;
    }

    public function create(User $user): bool
    {
        return $user->ist_admin;
    }

    public function update(User $user, Quelle $quelle): bool
    {
        return $user->ist_admin;
    }

    public function delete(User $user, Quelle $quelle): bool
    {
        return $user->ist_admin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->ist_admin;
    }

    public function reorder(User $user): bool
    {
        return $user->ist_admin;
    }
}
