@extends('emails.layout')

@section('title', 'Case Update Notification')
@section('preheader', 'A case activity update is available in your ASLAW workspace.')
@section('heading', 'New Case Activity')

@section('content')
    <p style="margin:0 0 16px 0;">Hello,</p>
    <p style="margin:0 0 20px 0;">There is a new update for <strong>{{ $caseTitle }}</strong> (Case ID: {{ $caseId }}).</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px 0;border:1px solid #e2e8f0;border-radius:14px;background-color:#f8fafc;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="margin:0 0 10px 0;"><strong>Update Type:</strong> {{ $actionLabel }}</div>
                @if(!empty($actorName))
                    <div style="margin:0 0 10px 0;"><strong>Updated By:</strong> {{ $actorName }}</div>
                @endif
                <div><strong>Details:</strong> {{ $summary }}</div>
            </td>
        </tr>
    </table>

    <p style="margin:0;">Please sign in to your ASLAW account to review the latest case information.</p>
@endsection