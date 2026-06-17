@extends('emails.layout')

@php($frontendUrl = rtrim(env('APP_FRONTEND_URL', config('app.url')), '/'))

@section('title', 'Your ASALAW Account Is Ready')
@section('preheader', 'Your ASALAW account has been created and is ready for first login.')
@section('heading', 'Welcome to ASALAW, ' . $user->name)

@section('content')
    <p style="margin:0 0 16px 0;">Your account has been created successfully. Use the credentials below to access the platform.</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px 0;border:1px solid #e2e8f0;border-radius:14px;background-color:#f8fafc;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="margin:0 0 12px 0;"><strong>Username:</strong> {{ $user->username }}</div>
                <div><strong>Temporary Password:</strong> {{ $password }}</div>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
        <tr>
            <td>
                <a href="{{ $frontendUrl }}" style="display:inline-block;padding:14px 24px;border-radius:10px;background-color:#0f172a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Log In to ASALAW</a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 12px 0;">Your temporary password is valid for <strong>10 minutes</strong> from the time this email was sent. At first login, you will be guided to your profile to reset it immediately.</p>
    <p style="margin:0 0 12px 0;">If the 10 minutes has passed before you log in, please contact the admin to issue a new password.</p>
    <p style="margin:0;">If the button does not work, open this link in your browser: <span style="color:#1d4ed8;word-break:break-all;">{{ $frontendUrl }}</span></p>
@endsection
