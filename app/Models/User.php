<?php

namespace App\Models;

use App\Services\FirmIdGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'role',
        'status',
        'age',
        'ICNumber',
        'phoneNumber',
        'HomeAddress',
        'gender',
        'maritalStatus',
        'firmID',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relationships
    public function lawyerCases()
    {
        return $this->hasMany(LawCase::class, 'lawyerID', 'id');
    }

    public function clientCases()
    {
        return $this->hasMany(LawCase::class, 'clientID', 'id');
    }

    // Auto-generate firmID & prevent modification
    protected static function booted()
    {
        static::creating(function ($user) {
            if (!$user->firmID) {
                $user->firmID = FirmIdGenerator::generate($user->role);
            }
        });

        static::updating(function ($user) {
            if ($user->isDirty('firmID')) {
                throw new \Exception('firmID cannot be modified.');
            }
        });
    }
}
