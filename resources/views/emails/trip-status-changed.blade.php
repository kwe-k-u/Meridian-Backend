@extends('emails.layout')

@section('title', 'Trip status update')
@section('heading', 'Trip Status Update')
@section('subheading', $companyName)

@section('content')
    <p style="margin: 0 0 12px; font-size: 16px; line-height: 1.7;">Hello {{ $recipientName }},</p>
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.7;">
        Your trip <strong>{{ $tripName }}</strong> with {{ $companyName }} has been updated to:
    </p>
    <p style="margin: 0 0 16px; text-align: center;">
        <span style="display: inline-block; background-color: #f8fafc; border-left: 4px solid #2563eb; padding: 10px 20px; border-radius: 6px; font-size: 16px; font-weight: 600; text-transform: capitalize;">{{ str_replace('_', ' ', $status) }}</span>
    </p>
    <p style="margin: 20px 0 0; font-size: 14px; line-height: 1.7; color: #64748b;">Reach out to your travel agent if you have any questions about this update.</p>
@endsection
