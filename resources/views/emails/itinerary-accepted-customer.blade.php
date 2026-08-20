@extends('emails.layout')

@section('title', 'Itinerary confirmed')
@section('heading', 'Itinerary Confirmed')
@section('subheading', $companyName)

@section('content')
    <p style="margin: 0 0 12px; font-size: 16px; line-height: 1.7;">Hello {{ $recipientName }},</p>
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.7;">
        Your itinerary <strong>{{ $itineraryName }}</strong> for <strong>{{ $tripName }}</strong> has been confirmed.
    </p>
    <p style="margin: 20px 0 0; font-size: 14px; line-height: 1.7; color: #64748b;">{{ $companyName }} will be in touch with next steps. We're excited for your trip!</p>
@endsection
