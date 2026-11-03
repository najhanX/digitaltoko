<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'nik', 'address', 'ktp_path', 'birth_place', 'birth_date'])]
class User extends Authenticatable
{
    protected $attributes = ['role' => 'owner'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function requiresEmailVerification(): bool
    {
        return ! $this->email_verified_at || ! filter_var($this->email, FILTER_VALIDATE_EMAIL)
            || str_ends_with(strtolower($this->email), '@identity.invalid');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'document_purge_at' => 'datetime',
            'nik' => 'encrypted',
            'address' => 'encrypted',
            'birth_place' => 'encrypted',
            'birth_date' => 'encrypted',
            'password' => 'hashed',
        ];
    }
}
