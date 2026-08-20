@extends('emails.layout')

@section('title', 'Payment received')
@section('heading', 'Payment Received')
@section('subheading', $companyName)

@section('content')
    <p style="margin: 0 0 12px; font-size: 16px; line-height: 1.7;">Hello {{ $recipientName }},</p>
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.7;">
        We've received your payment for <strong>{{ $tripName }}</strong>.
    </p>
    <p style="margin: 0 0 16px; text-align: center;">
        <span style="display: inline-block; background-color: #f8fafc; border-left: 4px solid #16a34a; padding: 10px 20px; border-radius: 6px; font-size: 20px; font-weight: 700;">{{ $currency }} {{ number_format($amount, 2) }}</span>
    </p>
    <p style="margin: 20px 0 0; font-size: 14px; line-height: 1.7; color: #64748b;">Thank you for booking with {{ $companyName }}. Keep this email for your records.</p>
@endsection
