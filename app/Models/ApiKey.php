<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory;

    protected ?string $plainTextKey = null;

    protected $fillable = [
        'name',
        'key',
        'key_hash',
        'user_id',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $apiKey): void {
            if (! $apiKey->isDirty('key')) {
                return;
            }

            $rawKey = $apiKey->getRawOriginal('key') !== $apiKey->getAttribute('key')
                ? (string) $apiKey->getAttribute('key')
                : null;

            if ($rawKey === null || $rawKey === '') {
                return;
            }

            $apiKey->plainTextKey = $rawKey;
            $apiKey->attributes['key_hash'] = self::hashKey($rawKey);
            $apiKey->attributes['key'] = self::maskKey($rawKey);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateKey(): string
    {
        return 'ck_'.Str::random(40);
    }

    public static function issueForUser(int $userId, array $attributes): self
    {
        return static::create([
            'name' => $attributes['name'],
            'expires_at' => $attributes['expires_at'] ?? null,
            'is_active' => $attributes['is_active'] ?? true,
            'user_id' => $userId,
            'key' => static::generateKey(),
        ]);
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function maskKey(string $key): string
    {
        return substr($key, 0, 6).'...'.substr($key, -4);
    }

    public function getKeyAttribute(?string $value): ?string
    {
        if ($this->plainTextKey !== null) {
            return $this->plainTextKey;
        }

        return $this->attributes['key_hash'] ?? null ? null : $value;
    }
}
