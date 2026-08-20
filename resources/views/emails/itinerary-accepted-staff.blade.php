@extends('emails.layout')

@section('title', 'Itinerary accepted')
@section('heading', 'Itinerary Accepted')
@section('subheading', $tripName)

@section('content')
    <p style="margin: 0 0 12px; font-size: 16px; line-height: 1.7;">Hello {{ $recipientName }},</p>
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 1.7;">
        {{ $customerName }} has accepted the <strong>{{ $itineraryName }}</strong> itinerary for <strong>{{ $tripName }}</strong>.
    </p>
    <p style="margin: 20px 0 0; font-size: 14px; line-height: 1.7; color: #64748b;">Log in to Meridian to review the trip and follow up with next steps.</p>
@endsection
