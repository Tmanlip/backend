@extends('emails.layout')

@section('title', 'Your OTP Code')
@section('preheader', 'Use your ASLAW verification code to complete sign in.')
@section('heading', 'Your Verification Code')

@section('content')
    <p style="margin:0 0 16px 0;">Hello,</p>
    <p style="margin:0 0 20px 0;">Use the verification code below to continue your ASLAW sign-in request.</p>

    <div style="margin:0 0 24px 0;padding:18px 20px;border-radius:14px;background-color:#eff6ff;border:1px solid #bfdbfe;text-align:center;">
        <div style="font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#1d4ed8;margin-bottom:8px;">One-Time Password</div>
        <div style="font-size:34px;line-height:1;font-weight:700;letter-spacing:0.2em;color:#0f172a;">{{ $otp }}</div>
    </div>

    <p style="margin:0 0 12px 0;">This code will expire in 10 minutes.</p>
    <p style="margin:0;">If you did not request this code, you can safely ignore this email.</p>
@endsection