<?php

namespace App\Policies;

use App\Models\NpsTunnel;
use App\Models\User;

class NpsTunnelPolicy
{
    public function view(User $user, NpsTunnel $tunnel): bool
    {
        return $user->is_admin
            || ($tunnel->client && $tunnel->client->user_id === $user->id);
    }

    public function manage(User $user, NpsTunnel $tunnel): bool
    {
        return $user->is_admin
            || ($tunnel->client && $tunnel->client->user_id === $user->id);
    }
}
