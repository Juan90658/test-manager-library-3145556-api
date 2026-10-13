<?php

namespace App\Adapters\Outbound\Infrastructure;

use App\Application\Ports;

/** UTC system clock. The offset only exists for the demo seeder (tool repo). */
final class SystemClock implements Ports\Clock
{
    public function __construct(private int $offsetDays = 0) {}

    public function now(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));

        return $this->offsetDays === 0 ? $now : $now->modify("{$this->offsetDays} days");
    }
}

/** UUID v4 (the contract requires v4; Laravel's HasUuids would produce v7). */
final class UuidV4Generator implements Ports\IdGenerator
{
    public function next(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

/** Argon2id (plan D-09). The hash never leaves the adapter and is never logged. */
final class Argon2PasswordHasher implements Ports\PasswordHasher
{
    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}

/** JWT HS256, 8 hours, with `sub` and `role`; no personal data (api-contract §4). */
final class JwtTokenIssuer implements Ports\TokenIssuer
{
    public function __construct(private string $secret, private int $ttl, private Ports\Clock $clock)
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters long.');
        }
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    public function issue(string $userId, string $role): array
    {
        // Expiry uses the real clock: the demo offset must not shorten or extend sessions.
        $iat = time();
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64(json_encode(['sub' => $userId, 'role' => $role, 'iat' => $iat, 'exp' => $iat + $this->ttl]));
        $sig = self::b64(hash_hmac('sha256', "{$header}.{$payload}", $this->secret, true));

        return ['access_token' => "{$header}.{$payload}.{$sig}", 'expires_in' => $this->ttl];
    }

    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::unb64($h), true);
        if (($header['alg'] ?? null) !== 'HS256') {
            return null; // rejects "alg":"none" and any other algorithm
        }
        $expected = self::b64(hash_hmac('sha256', "{$h}.{$p}", $this->secret, true));
        if (! hash_equals($expected, $s)) {
            return null;
        }
        $claims = json_decode((string) self::unb64($p), true);
        if (! is_array($claims) || ! isset($claims['sub'], $claims['role'], $claims['exp']) || $claims['exp'] < time()) {
            return null;
        }

        return ['sub' => (string) $claims['sub'], 'role' => (string) $claims['role']];
    }
}

/** Covers on disk. The key is opaque (uuid + extension), never a path (plan D-08). */
final class DiskCoverStorage implements Ports\CoverStorage
{
    public function __construct(private string $dir, private Ports\IdGenerator $ids) {}

    private function path(string $key): ?string
    {
        if (! preg_match('/^[0-9a-f-]{36}\.(jpg|png)$/', $key)) {
            return null; // never follow a key that looks like a path
        }

        return rtrim($this->dir, '/').'/'.$key;
    }

    public function put(string $bytes, string $extension): string
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
        $key = $this->ids->next().'.'.$extension;
        file_put_contents($this->path($key), $bytes);

        return $key;
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);

        return $path !== null && is_file($path) ? file_get_contents($path) : null;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }
}
