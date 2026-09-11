<?php
namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function view(User $user, Message $message)
    {
        return $user->id === $message->user_id || $user->role === 'admin';
    }

    public function create(User $user)
    {
        return $user->role === 'investor';
    }
}
