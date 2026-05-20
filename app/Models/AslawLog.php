<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AslawLog extends Model
{
    protected static bool $indexEnsured = false;

    protected $connection = 'mongodb';
    protected $collection = 'aslaw-logs';

    protected $fillable = [
        'user_id',
        'firm_id',
        'email',
        'method',
        'path',
        'route_name',
        'status_code',
        'severity',
        'service',
        'module',
        'ip',
        'user_agent',
        'query',
        'payload',
        'response_time_ms',
        'created_at',
    ];

    public $timestamps = false;

    protected $casts = [
        'query' => 'array',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Ensure the most important index for per-user timeline queries.
     */
    public static function ensureImportantIndex(): void
    {
        if (self::$indexEnsured) {
            return;
        }

        try {
            self::raw(function ($collection): void {
                $collection->createIndex([
                    'user_id' => 1,
                    'created_at' => -1,
                ]);
            });
        } catch (\Throwable $e) {
            // Do not block app flow if index creation fails.
        }

        self::$indexEnsured = true;
    }
}