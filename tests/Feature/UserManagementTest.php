<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function userManagementToken(object $test, string $role = 'admin'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Team Test',
        'slug' => uniqid('team-test-'),
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => uniqid('admin-').'@flowforge.test',
        'role' => $role,
    ]);

    $token = $test->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('data.token');

    return [$token, $tenant, $user];
}

it('lets tenant admins create users in their tenant', function () {
    [$token, $tenant] = userManagementToken($this);

    $response = $this->withToken($token)->postJson('/api/v1/users', [
        'name' => 'New Editor',
        'email' => 'new.editor@flowforge.test',
        'role' => 'editor',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'New Editor')
        ->assertJsonPath('data.email', 'new.editor@flowforge.test')
        ->assertJsonPath('data.role', 'editor')
        ->assertJsonPath('data.status', 'active');

    $created = User::query()->findOrFail($response->json('data.id'));

    expect($created->tenant_id)->toBe($tenant->id)
        ->and(Hash::check('password123', $created->password))->toBeTrue();
});

it('rejects non-admin user creation', function () {
    [$token] = userManagementToken($this, 'editor');

    $this->withToken($token)->postJson('/api/v1/users', [
        'name' => 'Blocked User',
        'email' => 'blocked@flowforge.test',
        'role' => 'viewer',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertForbidden();
});

it('requires unique emails inside a tenant when creating users', function () {
    [$token, $tenant] = userManagementToken($this);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'duplicate@flowforge.test',
        'role' => 'viewer',
    ]);

    $this->withToken($token)->postJson('/api/v1/users', [
        'name' => 'Duplicate User',
        'email' => 'duplicate@flowforge.test',
        'role' => 'viewer',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});
