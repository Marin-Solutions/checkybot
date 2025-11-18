<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable,TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url ? url(Storage::url($this->avatar_url)) : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class, 'created_by');
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'created_by');
    }

    public function globalNotificationChannels(): HasMany
    {
        return $this->hasMany(NotificationSetting::class)->globalScope()->active();
    }

    public function webhookChannels(): HasMany
    {
        return $this->hasMany(NotificationChannels::class, 'created_by');
    }

    public function monitorApis(): HasMany
    {
        return $this->hasMany(MonitorApis::class, 'created_by');
    }

    public function monitorApiResults(): HasMany
    {
        return $this->hasManyThrough(MonitorApiResult::class, MonitorApis::class, 'created_by', 'monitor_api_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }
}
