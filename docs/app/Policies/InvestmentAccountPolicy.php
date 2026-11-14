<?php
namespace App\Policies;

use App\Models\InvestmentAccount;
use App\Models\User;

class InvestmentAccountPolicy
{
    public function view(User $user, InvestmentAccount $investment)
    {
        return $user->id === $investment->user_id || $user->role === 'admin';
    }

    public function create(User $user)
    {
        return $user->role === 'investor';
    }
}

