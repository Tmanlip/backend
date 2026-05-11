<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'case_id',
        'organizer_user_id',
        'lawyerID',
        'clientID',
        'meeting_method',
        'agenda',
        'timezone',
        'start_at',
        'end_at',
        'google_event_id',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function lawCase()
    {
        return $this->belongsTo(LawCase::class, 'case_id', 'caseId');
    }

    public function lawyer()
    {
        return $this->belongsTo(User::class, 'lawyerID', 'id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'clientID', 'id');
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_user_id', 'id');
    }
}
