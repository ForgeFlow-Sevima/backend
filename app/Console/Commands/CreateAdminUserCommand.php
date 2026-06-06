<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUserCommand extends Command
{
    protected $signature = 'flowforge:create-admin
        {--name=ForgeFlow Admin : Admin display name}
        {--email=admin@flowforge.test : Admin email address}
        {--password= : Admin password. If omitted, the command prompts securely.}
        {--tenant-name=ForgeFlow : Tenant display name}
        {--tenant-slug=forgeflow : Tenant slug}';

    protected $description = 'Create or update the initial production admin user.';

    public function handle(): int
    {
        $password = (string) ($this->option('password') ?: $this->secret('Admin password'));

        if ($password === '') {
            $this->error('Password is required.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => (string) $this->option('tenant-slug')],
            ['name' => (string) $this->option('tenant-name'), 'status' => 'active'],
        );

        $user = User::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => (string) $this->option('email')],
            [
                'name' => (string) $this->option('name'),
                'password' => Hash::make($password),
                'role' => 'admin',
            ],
        );

        $this->info("Admin user ready: {$user->email}");

        return self::SUCCESS;
    }
}
