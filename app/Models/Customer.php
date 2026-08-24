<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['account_number', 'name', 'first_name', 'middle_name', 'last_name', 'email', 'password', 'mobile_number', 'address', 'avatar_path', 'status', 'theme_preference', 'two_factor_method', 'two_factor_secret', 'two_factor_enabled_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $appends = ['avatar_url', 'two_factor_enabled'];

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (blank($customer->account_number)) {
                $customer->account_number = static::generateAccountNumber();
            }
        });
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    public function getTwoFactorEnabledAttribute(): bool
    {
        return filled($this->two_factor_method) && $this->two_factor_enabled_at !== null;
    }

    public function twoFactorChallenges(): HasMany
    {
        return $this->hasMany(CustomerTwoFactorChallenge::class);
    }

    public function replaceAvatar(UploadedFile $file): void
    {
        if ($this->avatar_path) {
            Storage::disk('public')->delete($this->avatar_path);
        }

        $path = $file->store('customer-avatars', 'public');

        $this->forceFill(['avatar_path' => $path])->save();
    }

    public function clearAvatar(): void
    {
        if ($this->avatar_path) {
            Storage::disk('public')->delete($this->avatar_path);
        }

        $this->forceFill(['avatar_path' => null])->save();
    }

    public static function generateAccountNumber(): string
    {
        do {
            $accountNumber = 'CUST-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));
        } while (static::query()->where('account_number', $accountNumber)->exists());

        return $accountNumber;
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'string',
            'two_factor_secret' => 'encrypted',
            'two_factor_enabled_at' => 'datetime',
        ];
    }
}
