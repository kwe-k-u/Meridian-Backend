@extends('emails.layout')

@section('title', 'Reset your password')
@section('heading', 'Password Reset Request')
@section('subheading', 'Meridian Travel')

@section('content')
    <p style="margin: 0 0 12px; font-size: 16px; line-height: 1.7;">Hello {{ $recipientName }},</p>
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.7;">We received a request to reset your Meridian password. Click the button below to choose a new one.</p>

    <p style="margin: 24px 0; text-align: center;">
        <a href="{{ $resetUrl }}" style="display: inline-block; background-color: #1d4ed8; color: #ffffff; text-decoration: none; font-weight: 600; padding: 12px 28px; border-radius: 6px; font-size: 15px;">Reset Password</a>
    </p>

    <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.7; color: #64748b;">If the button doesn't work, copy and paste this link into your browser:</p>
    <p style="margin: 0 0 16px; font-size: 13px; line-height: 1.7; color: #1d4ed8; word-break: break-all;">{{ $resetUrl }}</p>

    <p style="margin: 20px 0 0; font-size: 14px; line-height: 1.7; color: #64748b;">This link expires shortly for your security. If you didn't request a password reset, you can safely ignore this email.</p>
@endsection
