<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use app\Http\Controllers\Api\LawCaseController;

class LawCase extends Model
{
    use HasFactory;

    protected $table = 'law_cases';
    protected $primaryKey = 'caseId';

    protected $fillable = [
        'title',
        'description',
        'status',
        'lawyerID',
        'clientID',
        'lawyerFirmID',
        'clientFirmID',
    ];

    public function lawyer()
    {
        return $this->belongsTo(User::class, 'lawyerID', 'id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'clientID', 'id');
    }
}
