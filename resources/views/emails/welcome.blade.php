@extends('emails.layout')

@section('title', 'Welcome to Meridian')
@section('heading', 'Welcome to Meridian')
@section('subheading', 'Your workspace is ready')

@section('content')
    <p style="margin: 0 0 12px; font-size: 16px; line-height: 1.7;">Hello {{ $recipientName }},</p>
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.7;">
        Thanks for creating <strong>{{ $companyName }}</strong> on Meridian. Your workspace is set up and ready to go —
        you can start adding customers, planning trips, and building itineraries right away.
    </p>
    <p style="margin: 20px 0 0; font-size: 14px; line-height: 1.7; color: #64748b;">If you have any questions, just reply to this email — we're happy to help.</p>
@endsection
