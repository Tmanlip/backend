<?php

namespace App\Support;

class LogClassification
{
    /**
     * @var array<string, int>
     */
    private const SEVERITY_RANK = [
        'DEBUG' => 0,
        'INFO' => 1,
        'NOTICE' => 2,
        'LOW' => 3,
        'MEDIUM' => 4,
        'HIGH' => 5,
        'CRITICAL' => 6,
        'SECURITY' => 7,
        'AUDIT' => 2,
    ];

    public static function deriveInteraction(string $method, string $path): string
    {
        $method = strtoupper(trim($method));
        $path = strtolower(trim($path, '/'));

        if ($path === '' || $path === 'api') {
            return 'view';
        }

        if (str_contains($path, 'upload')) {
            return 'upload';
        }

        if (str_contains($path, 'generate')) {
            return 'generate';
        }

        if (str_contains($path, 'download')) {
            return 'download';
        }

        if (str_contains($path, 'preview')) {
            return 'preview';
        }

        if (str_contains($path, 'share')) {
            return 'share';
        }

        if (str_contains($path, 'revoke')) {
            return 'revoke';
        }

        if (str_contains($path, 'login')) {
            return 'login';
        }

        if (str_contains($path, 'logout')) {
            return 'logout';
        }

        if (str_contains($path, 'register')) {
            return 'register';
        }

        if (str_contains($path, 'ask') || str_contains($path, 'chat')) {
            return 'chat';
        }

        if (str_contains($path, 'invoice')) {
            return 'invoice';
        }

        if (str_contains($path, 'meeting')) {
            return 'meeting';
        }

        if (str_contains($path, 'notification')) {
            return 'notification';
        }

        if (str_contains($path, 'user') || str_contains($path, 'profile')) {
            return 'user-management';
        }

        return match ($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'view',
        };
    }

    public static function deriveModule(string $path): string
    {
        $path = strtolower($path);

        if (str_contains($path, 'auth') || str_contains($path, 'login') || str_contains($path, 'logout') || str_contains($path, 'password') || str_contains($path, 'otp')) {
            return 'auth';
        }

        if (str_contains($path, 'case')) {
            return 'cases';
        }

        if (str_contains($path, 'invoice')) {
            return 'invoices';
        }

        if (str_contains($path, 'meeting')) {
            return 'meetings';
        }

        if (str_contains($path, 'document') || str_contains($path, 'file') || str_contains($path, 'upload') || str_contains($path, 'download')) {
            return 'documents';
        }

        if (str_contains($path, 'notification')) {
            return 'notifications';
        }

        if (str_contains($path, 'user') || str_contains($path, 'lawyer') || str_contains($path, 'client') || str_contains($path, 'admin')) {
            return 'users';
        }

        return 'api';
    }

    public static function deriveSeverity(int $statusCode, string $method, string $path): string
    {
        $method = strtoupper($method);
        $path = strtolower($path);

        $isAuthPath = str_contains($path, 'login')
            || str_contains($path, 'logout')
            || str_contains($path, 'password')
            || str_contains($path, 'otp')
            || str_contains($path, 'reset')
            || str_contains($path, 'auth');

        $isAuditPath = str_contains($path, 'case')
            || str_contains($path, 'invoice')
            || str_contains($path, 'meeting')
            || str_contains($path, 'document')
            || str_contains($path, 'encrypted-documents')
            || str_contains($path, 'user')
            || str_contains($path, 'lawyer')
            || str_contains($path, 'client')
            || str_contains($path, 'admin');

        if ($statusCode >= 200 && $statusCode < 300 && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $isAuditPath) {
            return 'AUDIT';
        }

        if ($statusCode >= 100 && $statusCode <= 103) {
            return 'INFO';
        }

        return match ($statusCode) {
            200, 204 => 'INFO',
            201, 202 => 'NOTICE',
            301, 302 => 'LOW',
            304 => 'INFO',
            400 => 'LOW',
            401 => $isAuthPath ? 'SECURITY' : 'MEDIUM',
            403 => $isAuthPath ? 'SECURITY' : 'HIGH',
            404 => 'LOW',
            405 => $isAuthPath ? 'SECURITY' : 'MEDIUM',
            406 => 'LOW',
            408, 409, 413, 415, 422 => 'MEDIUM',
            410 => 'LOW',
            429 => 'SECURITY',
            500 => 'HIGH',
            501 => 'MEDIUM',
            502, 503, 504 => 'CRITICAL',
            default => $statusCode >= 500
                ? 'HIGH'
                : ($statusCode >= 400
                    ? 'MEDIUM'
                    : ($statusCode >= 300
                        ? 'LOW'
                        : 'DEBUG')),
        };
    }

    public static function shouldAlertAdmins(string $severity): bool
    {
        $severity = strtoupper(trim($severity));

        return (self::SEVERITY_RANK[$severity] ?? -1) >= self::SEVERITY_RANK['MEDIUM'];
    }
}
