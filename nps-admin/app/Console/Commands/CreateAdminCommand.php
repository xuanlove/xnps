<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 创建/提升管理员账号（管理控制台登录用）
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'nps:admin
        {email : 管理员邮箱}
        {--name=Admin : 显示名}
        {--password= : 密码（留空自动生成并输出）}';

    protected $description = '创建或提升一个管理员账号（is_admin=1）';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) ($this->option('password') ?: Str::password(16));

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) $this->option('name'),
                'password' => $password,
                'is_admin' => true,
            ],
        );

        $this->info(sprintf('admin ready: %s (#%d)', $user->email, $user->id));
        if (! $this->option('password')) {
            $this->warn('generated password: '.$password);
        }

        return self::SUCCESS;
    }
}
