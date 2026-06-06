<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(LoginRequest $request, JwtService $jwt): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->with('tenant')->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if ($user->tenant?->status !== 'active') {
            return response()->json(['message' => 'Tenant is not active.'], 403);
        }

        return $this->issueSession($user, $jwt);
    }

    public function register(RegisterRequest $request, JwtService $jwt): JsonResponse
    {
        $payload = $request->validated();

        $user = DB::transaction(function () use ($payload): User {
            $tenant = Tenant::query()->create([
                'name' => $payload['tenantName'],
                'slug' => $this->uniqueTenantSlug($payload['tenantName']),
                'status' => 'active',
            ]);

            return User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $payload['name'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'role' => 'admin',
            ])->load('tenant');
        });

        return $this->issueSession($user, $jwt);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant');

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'tenant' => new TenantResource($user->tenant),
                'permissions' => $this->permissionsFor($user->role),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('access_token')?->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function permissionsFor(string $role): array
    {
        return match ($role) {
            'admin' => ['dashboard:read', 'workflows:write', 'runs:write', 'ai:write', 'users:write'],
            'editor' => ['dashboard:read', 'workflows:write', 'runs:write', 'ai:write'],
            default => ['dashboard:read', 'workflows:read', 'runs:read', 'logs:read'],
        };
    }

    private function issueSession(User $user, JwtService $jwt): JsonResponse
    {
        $user->loadMissing('tenant');

        $issued = $jwt->issue([
            'sub' => $user->id,
            'tenant_id' => $user->tenant_id,
            'role' => $user->role,
        ]);

        $user->personalAccessTokens()->create([
            'name' => 'frontend-jwt',
            'token' => hash('sha256', $issued['jti']),
            'abilities' => '*',
            'expires_at' => now()->setTimestamp($issued['expiresAt']),
        ]);

        return response()->json([
            'data' => [
                'token' => $issued['token'],
                'user' => new UserResource($user),
                'tenant' => new TenantResource($user->tenant),
            ],
        ]);
    }

    private function uniqueTenantSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'tenant';
        $slug = $baseSlug;
        $suffix = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
