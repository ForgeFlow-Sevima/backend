<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates a production admin user and tenant', function () {
    $this->artisan('flowforge:create-admin', [
        '--name' => 'Production Admin',
        '--email' => 'admin@example.test',
        '--password' => 'secret-password',
        '--tenant-name' => 'Production Tenant',
        '--tenant-slug' => 'production-tenant',
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'production-tenant')->firstOrFail();
    $user = User::query()->where('tenant_id', $tenant->id)->where('email', 'admin@example.test')->firstOrFail();

    expect($tenant->name)->toBe('Production Tenant')
        ->and($tenant->status)->toBe('active')
        ->and($user->name)->toBe('Production Admin')
        ->and($user->role)->toBe('admin')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});
