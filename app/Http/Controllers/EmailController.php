<?php

namespace App\Http\Controllers;

use App\Mail\GmailMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailController extends Controller
{
    public function send(Request $request)
    {
        $body = trim((string) $request->input('body', $request->input('message', '')));
        $subject = trim((string) $request->input('subject', 'New Business Inquiry Received'));
        $recipient = trim((string) $request->input('recipient', config('mail.from.address')));
        $recipientName = trim((string) $request->input('recipient_name', 'Team'));

        if ($body === '') {
            return response()->json([
                'message' => 'Email body is required.',
            ], 422);
        }

        Mail::to($recipient)->send(new GmailMailer($body, $subject, $recipientName));

        return response()->json([
            'message' => 'Email sent successfully.',
            'recipient' => $recipient,
            'subject' => $subject,
        ]);
    }
}
