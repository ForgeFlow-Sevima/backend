<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues a jwt and authenticates me endpoint', function () {
    $tenant = Tenant::query()->create([
        'name' => 'FlowForge Test',
        'slug' => 'flowforge-test',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'admin@flowforge.test',
        'role' => 'admin',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $login->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.tenant.id', $tenant->id);

    $token = $login->json('data.token');
    expect(explode('.', $token))->toHaveCount(3);

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.tenant.slug', 'flowforge-test');
});

it('revokes jwt on logout', function () {
    $tenant = Tenant::query()->create([
        'name' => 'FlowForge Test',
        'slug' => 'flowforge-test',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'admin@flowforge.test',
        'role' => 'admin',
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->json('data.token');

    $this->withToken($token)
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('data.ok', true);

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

it('rejects missing or invalid jwt', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->withToken('not-a-jwt')->getJson('/api/v1/me')->assertUnauthorized();
});
