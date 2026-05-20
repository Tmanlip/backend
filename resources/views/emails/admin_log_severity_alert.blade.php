@extends('emails.layout')

@section('content')
    @php
        $severity = strtoupper((string) ($logData['severity'] ?? 'UNKNOWN'));
        $statusCode = (int) ($logData['status_code'] ?? 0);
    @endphp

    <h2 style="margin: 0 0 12px; color: #b91c1c;">Admin Security Alert</h2>

    <p style="margin: 0 0 12px;">A log event with severity <strong>{{ $severity }}</strong> was detected in ASLAW.</p>

    <table cellpadding="8" cellspacing="0" border="0" style="border-collapse: collapse; width: 100%; background: #f8fafc; border: 1px solid #e2e8f0;">
        <tr>
            <td style="font-weight: 600; width: 180px;">Severity</td>
            <td>{{ $severity }}</td>
        </tr>
        <tr>
            <td style="font-weight: 600;">Status Code</td>
            <td>{{ $statusCode }}</td>
        </tr>
        <tr>
            <td style="font-weight: 600;">Service</td>
            <td>{{ $logData['service'] ?? 'aslaw-backend' }}</td>
        </tr>
        <tr>
            <td style="font-weight: 600;">Module</td>
            <td>{{ $logData['module'] ?? 'api' }}</td>
        </tr>
        <tr>
            <td style="font-weight: 600;">Method</td>
            <td>{{ $logData['method'] ?? '-' }}</td>
        </tr>
        <tr>
            <td style="font-weight: 600;">Path</td>
            <td>{{ $logData['path'] ?? '-' }}</td>
        </tr>
        <tr>
            <td style="font-weight: 600;">User</td>
            <td>{{ $logData['email'] ?? ('User #' . ($logData['user_id'] ?? '-')) }}</td>
        </tr>
        <tr>
            <td style="font-weight: 600;">IP</td>
            <td>{{ $logData['ip'] ?? '-' }}</td>
        </tr>
        <tr>
            <td style="font-weight: 600;">Timestamp</td>
            <td>{{ $logData['created_at'] ?? now()->toDateTimeString() }}</td>
        </tr>
    </table>

    <p style="margin: 16px 0 0; color: #334155;">Please review the Admin Logs page for full context and take action if required.</p>
@endsection
