<?php

    namespace App\Models;

    use App\Services\FirmIdGenerator;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;
    use Laravel\Sanctum\HasApiTokens;
    use App\Services\UserKeyService;
    use Illuminate\Database\Eloquent\SoftDeletes;
    use App\Notifications\CustomResetPasswordNotification;

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
            'key',
            'rsa_public_key',
            'rsa_private_key',
            'firmID',
            'failed_login_attempts',
            'account_locked_at',
        ];

        protected $hidden = [
            'password',
            'remember_token',
            'key',
            'rsa_private_key',
        ];

        protected function casts(): array
        {
            return [
                'email_verified_at' => 'datetime',
                'password' => 'hashed',
                'account_locked_at' => 'datetime',
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

        public function lawyerMeetings()
        {
            return $this->hasMany(Meeting::class, 'lawyerID', 'id');
        }

        public function clientMeetings()
        {
            return $this->hasMany(Meeting::class, 'clientID', 'id');
        }

        public function organizedMeetings()
        {
            return $this->hasMany(Meeting::class, 'organizer_user_id', 'id');
        }

        // Auto-generate firmID & prevent modification
        protected static function booted()
        {
            static::creating(function ($user) {
                if (!$user->firmID) {
                    $user->firmID = FirmIdGenerator::generate($user->role);
                    $user->key = UserKeyService::generateKey();
                }
            });

            static::updating(function ($user) {
                if ($user->isDirty('firmID')) {
                    throw new \Exception('firmID cannot be modified.');
                }

                if ($user->isDirty('key')) {
                    throw new \Exception('Key cannot be modified.');
                }
            });
        }

    }
