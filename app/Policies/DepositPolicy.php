<?php
namespace App\Policies;

use App\Models\Deposit;
use App\Models\User;

class DepositPolicy
{
    public function view(User $user, Deposit $deposit)
    {
        return $user->id === $deposit->user_id || $user->role === 'admin';
    }

    public function create(User $user)
    {
        return $user->role === 'investor';
    }
}

# ---

