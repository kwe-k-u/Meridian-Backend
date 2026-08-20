@extends('emails.layout')

@section('title', 'You\'re invited to Meridian')
@section('heading', 'You\'re Invited')
@section('subheading', $companyName)

@section('content')
    <p style="margin: 0 0 12px; font-size: 16px; line-height: 1.7;">Hello,</p>
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.7;">
        {{ $inviterName }} has invited you to join <strong>{{ $companyName }}</strong> on Meridian
        @if ($role) as a <strong>{{ $role }}</strong> @endif.
    </p>

    <p style="margin: 24px 0; text-align: center;">
        <a href="{{ $acceptUrl }}" style="display: inline-block; background-color: #1d4ed8; color: #ffffff; text-decoration: none; font-weight: 600; padding: 12px 28px; border-radius: 6px; font-size: 15px;">Accept Invitation</a>
    </p>

    <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.7; color: #64748b;">If the button doesn't work, copy and paste this link into your browser:</p>
    <p style="margin: 0 0 16px; font-size: 13px; line-height: 1.7; color: #1d4ed8; word-break: break-all;">{{ $acceptUrl }}</p>

    <p style="margin: 20px 0 0; font-size: 14px; line-height: 1.7; color: #64748b;">If you weren't expecting this invitation, you can safely ignore this email.</p>
@endsection
