@extends('emails.layout')

@section('title', 'Try the Meridian demo')
@section('heading', 'You\'re Invited')
@section('subheading', 'Explore the Meridian demo account')

@section('content')
    <p style="margin: 0 0 12px; font-size: 16px; line-height: 1.7;">Hello,</p>
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.7;">
        {{ $inviterName }} has invited you to explore Meridian using our live demo account — no signup form, no password to set.
    </p>

    <p style="margin: 24px 0; text-align: center;">
        <a href="{{ $joinUrl }}" style="display: inline-block; background-color: #1d4ed8; color: #ffffff; text-decoration: none; font-weight: 600; padding: 12px 28px; border-radius: 6px; font-size: 15px;">Join the Demo</a>
    </p>

    <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.7; color: #64748b;">If the button doesn't work, copy and paste this link into your browser:</p>
    <p style="margin: 0 0 16px; font-size: 13px; line-height: 1.7; color: #1d4ed8; word-break: break-all;">{{ $joinUrl }}</p>

    <p style="margin: 20px 0 0; font-size: 14px; line-height: 1.7; color: #64748b;">You'll just need to tell us your name, then you're straight into the demo workspace.</p>
@endsection
