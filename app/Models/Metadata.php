<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Metadata extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Connection & Collection
    |--------------------------------------------------------------------------
    */

    protected $connection = 'mongodb';
    protected $collection = 'metadata';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignable Fields
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'type',               // user | case
        'firm_id',            // for user
        'case_id',            // for case
        'lawyer_firm_id',
        'client_firm_id',
        'blob_folder_path',
    ];

    public $timestamps = true;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Boot Method (Auto Structure Control)
    |--------------------------------------------------------------------------
    */

    protected static function booted()
    {
        static::creating(function ($model) {

            // Enforce schema structure consistency

            if ($model->type === 'user') {
                $model->case_id = null;
                $model->lawyer_firm_id = null;
                $model->client_firm_id = null;
                $model->blob_folder_path = null;
            }

            if ($model->type === 'case') {

                // Auto-generate Azure folder path if not provided
                if (empty($model->blob_folder_path) && !empty($model->case_id)) {
                    $model->blob_folder_path = "cases/{$model->case_id}/";
                }
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeUsers(Builder $query): Builder
    {
        return $query->where('type', 'user');
    }

    public function scopeCases(Builder $query): Builder
    {
        return $query->where('type', 'case');
    }

    public function scopeByFirmId(Builder $query, string $firmId): Builder
    {
        return $query->where('firm_id', $firmId);
    }

    public function scopeByCaseId(Builder $query, string $caseId): Builder
    {
        return $query->where('case_id', $caseId);
    }

    /*
    |--------------------------------------------------------------------------
    | Static Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Store user metadata
     */
    public static function storeUser(string $firmId): self
    {
        return self::firstOrCreate(
            [
                'type' => 'user',
                'firm_id' => $firmId
            ]
        );
    }

    /**
     * Store or update case metadata
     */
    public static function storeCase(
        string $caseId,
        string $lawyerFirmId,
        string $clientFirmId
    ): self {

        return self::updateOrCreate(
            [
                'type' => 'case',
                'case_id' => $caseId
            ],
            [
                'lawyer_firm_id' => $lawyerFirmId,
                'client_firm_id' => $clientFirmId,
                'blob_folder_path' => "cases/{$caseId}/"
            ]
        );
    }
}
