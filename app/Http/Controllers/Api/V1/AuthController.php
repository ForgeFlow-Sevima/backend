<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
}
