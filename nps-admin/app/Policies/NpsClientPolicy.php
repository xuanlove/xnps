<?php

namespace App\Policies;

use App\Models\NpsClient;
use App\Models\NpsTunnel;
use App\Models\User;

/**
 * 归属权限（DESIGN.md §8.4）：用户只能访问自己的资源
 */
class NpsClientPolicy
{
    public function view(User $user, NpsClient $client): bool
    {
        return $user->is_admin || $client->user_id === $user->id;
    }

    public function manage(User $user, NpsClient $client): bool
    {
        // 管理操作（隧道申请等）：仅归属用户或管理员
        return $user->is_admin || $client->user_id === $user->id;
    }
}
