<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenMiddleware
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $claims = $this->jwt->validate($bearerToken);
        } catch (\Throwable) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $accessToken = PersonalAccessToken::query()
            ->where('token', hash('sha256', (string) $claims['jti']))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $accessToken || ! $accessToken->tokenable instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = $accessToken->tokenable()->with('tenant')->first();

        if (! $user || $user->id !== $claims['sub'] || $user->tenant_id !== ($claims['tenant_id'] ?? null) || $user->tenant?->status !== 'active') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('access_token', $accessToken);
        $request->attributes->set('jwt_claims', $claims);

        return $next($request);
    }
}
