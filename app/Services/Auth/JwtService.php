<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;
use InvalidArgumentException;

class JwtService
{
    private const ALGORITHM = 'HS256';

    public function issue(array $claims, ?string $jti = null, ?int $ttlSeconds = null): array
    {
        $issuedAt = now()->timestamp;
        $expiresAt = $issuedAt + ($ttlSeconds ?? 86400);
        $jti ??= (string) Str::uuid();

        $payload = array_merge($claims, [
            'jti' => $jti,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ]);

        $token = $this->encode(['typ' => 'JWT', 'alg' => self::ALGORITHM], $payload);

        return compact('token', 'jti', 'expiresAt');
    }

    public function validate(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Malformed JWT.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->decodePart($encodedHeader);
        $payload = $this->decodePart($encodedPayload);

        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new InvalidArgumentException('Unsupported JWT algorithm.');
        }

        $expectedSignature = $this->sign($encodedHeader.'.'.$encodedPayload);

        if (! hash_equals($expectedSignature, $encodedSignature)) {
            throw new InvalidArgumentException('Invalid JWT signature.');
        }

        if (! isset($payload['sub'], $payload['jti'], $payload['exp']) || (int) $payload['exp'] <= now()->timestamp) {
            throw new InvalidArgumentException('Expired JWT.');
        }

        return $payload;
    }

    private function encode(array $header, array $payload): string
    {
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->sign($encodedHeader.'.'.$encodedPayload);

        return $encodedHeader.'.'.$encodedPayload.'.'.$signature;
    }

    private function decodePart(string $value): array
    {
        $decoded = base64_decode($this->base64UrlToBase64($value), true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid JWT encoding.');
        }

        $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid JWT payload.');
        }

        return $data;
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret(), true));
    }

    private function secret(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlToBase64(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return strtr($value, '-_', '+/');
    }
}
