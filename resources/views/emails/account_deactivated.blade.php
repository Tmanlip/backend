@extends('emails.layout')

@section('title', 'Account Deactivated')
@section('preheader', 'Your ASALAW account status has been changed by an administrator.')
@section('heading', 'Account Status Update')

@section('content')
    <p style="margin:0 0 16px 0;">Dear {{ $userName }},</p>
    <p style="margin:0 0 16px 0;">This email is to inform you that your ASALAW account has been deactivated by an administrator.</p>
    <p style="margin:0 0 16px 0;">You may still be able to access the sign-in and sign-out flow, but access to system features and stored data has been restricted.</p>
    <p style="margin:0 0 16px 0;">If you believe this action was taken in error or you need clarification, please contact your administrator.</p>
    <p style="margin:0;">Regards,<br>ASALAW Team</p>
@endsection
