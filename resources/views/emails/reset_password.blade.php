@extends('emails.layout')

@section('title', 'Reset Your Password')
@section('preheader', 'Reset your ASALAW password securely from the link in this email.')
@section('heading', 'Password Reset Request')

@section('content')
    <p style="margin:0 0 16px 0;">We received a request to reset your ASALAW account password.</p>
    <p style="margin:0 0 24px 0;">Use the button below to choose a new password. For security reasons, this link should only be used by you.</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
        <tr>
            <td>
                <a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 24px;border-radius:10px;background-color:#0f172a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Reset Password</a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 12px 0;">If the button does not work, copy and paste this link into your browser:</p>
    <p style="margin:0 0 20px 0;word-break:break-all;color:#1d4ed8;">{{ $resetUrl }}</p>
    <p style="margin:0;">If you did not request a password reset, no further action is required.</p>
@endsection
