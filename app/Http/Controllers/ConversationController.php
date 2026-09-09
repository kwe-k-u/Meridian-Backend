<?php

namespace App\Http\Controllers;

use App\Helpers\UserHelper;
use App\Models\Conversation;
use App\Models\GmailAccount;
use App\Models\Message;
use App\Models\Trip;
use App\Services\AI\MeridianAiService;
use App\Services\Gmail\GmailOAuthService;
use App\Services\Gmail\GmailService;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Read access to synced conversations (currently Gmail-only), the "which trip does this belong
 * to" triage action, untracking, and sending real replies (+ AI-drafted suggestions for them).
 * Untracking only stops Meridian from following the thread; it never touches the actual mailbox
 * (no Gmail API call for that one — see GmailThreadController for the opt-in add side of this).
 *
 * Routes: /api/conversations (index/show/destroy), PATCH /api/conversations/{conversation},
 * POST /api/conversations/{conversation}/messages, POST /api/conversations/{conversation}/suggest-reply
 */
class ConversationController extends Controller
{
    // GET /api/conversations — list, newest activity first. No message bodies here (see show())
    // — just enough (customer/trip/latest message preview) to render an inbox row.
    public function index(Request $request): JsonResponse
    {
        $conversations = Conversation::where('company_id', UserHelper::user_company($request)->company_id)
            ->with(['customer', 'trip', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->get();

        return response()->json($conversations);
    }

    // GET /api/conversations/{conversation} — full thread. Marks it read (unread_count -> 0)
    // as a side effect, same as opening any inbox thread.
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($conversation->unread_count > 0) {
            $conversation->unread_count = 0;
            $conversation->save();
        }

        return response()->json($conversation->load([
            'customer',
            'trip',
            'messages' => fn ($q) => $q->orderBy('sent_at'),
        ]));
    }

    // PATCH /api/conversations/{conversation} — sets (or clears, if trip_id is null) which
    // trip this conversation is linked to. Deliberately narrow: only trip_id, not customer_id
    // (that stays whatever PollGmailAccountJob already matched by email).
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $company = UserHelper::user_company($request);

        if ($conversation->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'trip_id' => 'nullable|string|exists:trips,trip_id',
        ]);

        // 'exists' above only checks the row is present, not that it belongs to this caller's
        // company — without this, a valid trip_id from a different company would silently link
        // across tenants.
        if (!empty($validated['trip_id'])) {
            $trip = Trip::find($validated['trip_id']);
            if (!$trip || $trip->company_id !== $company->company_id) {
                return response()->json(['message' => 'Trip not found.'], 422);
            }
        }

        $conversation->trip_id = $validated['trip_id'] ?? null;
        $conversation->save();

        return response()->json($conversation->load(['customer', 'trip']));
    }

    // DELETE /api/conversations/{conversation} — untrack. Deletes the conversation's messages
    // then the conversation row itself; the source thread in Gmail is untouched. If the agent
    // wants it back, re-adding via GmailThreadController::store() re-syncs it from scratch.
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $conversation->messages()->delete();
        $conversation->delete();

        return response()->json(['message' => 'Conversation untracked.']);
    }

    // POST /api/conversations/{conversation}/messages — Sends a real reply into the tracked
    // Gmail thread via the company's connected mailbox (GmailService::sendMessage), then stores
    // it locally so it appears immediately in the thread without waiting for
    // PollGmailAccountJob's next poll cycle.
    public function sendMessage(Request $request, Conversation $conversation, GmailOAuthService $oauth): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($conversation->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($conversation->channel !== 'gmail') {
            return response()->json(['message' => 'Replying is only supported for Gmail conversations right now.'], 422);
        }

        $validated = $request->validate(['body' => 'required|string']);

        $account = GmailAccount::where('company_id', $company->company_id)
            ->where('status', 'active')
            ->first();
        if (!$account) {
            return response()->json(['message' => 'Connect Gmail in Settings first.'], 422);
        }

        $conversation->loadMissing('customer');
        $to = $conversation->customer?->email;
        if (!$to) {
            $lastInbound = $conversation->messages()->where('direction', 'inbound')->latest('sent_at')->first();
            $to = $lastInbound?->from_email;
        }
        if (!$to) {
            return response()->json(['message' => 'Could not determine who to reply to.'], 422);
        }

        $subject = $conversation->subject ?? '(no subject)';
        if (!preg_match('/^Re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        // Gmail only renders a sent message as part of the existing thread (and every other mail
        // client relies on this too) when In-Reply-To/References chain back to the parent
        // message's Message-ID — threadId alone isn't enough. Chain off whichever message is
        // actually latest in the thread, not just the latest inbound one.
        $parent = $conversation->messages()->whereNotNull('rfc_message_id')->latest('sent_at')->first();
        $inReplyTo = $parent?->rfc_message_id;
        $references = $parent ? trim(($parent->rfc_references ? $parent->rfc_references . ' ' : '') . $parent->rfc_message_id) : null;

        try {
            $sent = (new GmailService($account, $oauth))->sendMessage(
                $conversation->external_thread_id,
                $to,
                $subject,
                $validated['body'],
                $inReplyTo,
                $references,
            );
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Could not send the reply: ' . $e->getMessage()], 502);
        }

        $message = Message::create([
            'message_id' => IdGeneratorService::generateId('MSG'),
            'conversation_id' => $conversation->conversation_id,
            'external_message_id' => $sent['message']->getId(),
            'rfc_message_id' => $sent['messageId'],
            'rfc_references' => $references,
            'direction' => 'outbound',
            'from_email' => $account->google_email,
            'from_name' => null,
            'body_text' => $validated['body'],
            'snippet' => Str::limit($validated['body'], 140),
            'sent_at' => now(),
        ]);

        $conversation->last_message_at = $message->sent_at;
        $conversation->save();

        return response()->json($message, 201);
    }

    // POST /api/conversations/{conversation}/suggest-reply — Asks meridian-ai for draft reply
    // suggestions based on this thread's history (and the linked trip's context, if any). Only
    // called when the agent explicitly clicks "Suggest reply" in the UI — never automatically.
    public function suggestReply(Request $request, Conversation $conversation, MeridianAiService $ai): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($conversation->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($conversation->channel !== 'gmail') {
            return response()->json(['message' => 'Suggestions are only supported for Gmail conversations right now.'], 422);
        }

        $conversation->loadMissing(['trip', 'messages' => fn ($q) => $q->orderBy('sent_at')->limit(20)]);

        $history = $conversation->messages->map(fn (Message $m) => [
            'role' => $m->direction === 'outbound' ? 'assistant' : 'user',
            'content' => $m->body_text ?? $m->snippet ?? '',
            'timestamp' => $m->sent_at?->toIso8601String(),
        ])->all();

        $trip = $conversation->trip;
        $tripContext = $trip ? [
            'trip_name' => $trip->trip_name,
            'status' => $trip->status->value,
            'start_date' => $trip->start_date?->toDateString(),
            'end_date' => $trip->end_date?->toDateString(),
            'budget' => $trip->budget,
        ] : null;

        try {
            $result = $ai->suggestResponse([
                'conversation_history' => $history,
                'trip_context' => $tripContext,
                'num_suggestions' => 3,
            ]);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => 'Could not draft a suggestion right now.'], 502);
        }

        return response()->json($result);
    }

    // POST /api/conversations/{conversation}/suggest-reply — Asks meridian-ai for draft reply
    // suggestions based on this thread's history (and the linked trip's context, if any). Only
    // called when the agent explicitly clicks "Suggest reply" in the UI — never automatically.
    public function requestTravelDetails(Request $request, Conversation $conversation, MeridianAiService $ai): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($conversation->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($conversation->channel !== 'gmail') {
            return response()->json(['message' => 'Suggestions are only supported for Gmail conversations right now.'], 422);
        }

        $conversation->loadMissing(['trip', 'messages' => fn ($q) => $q->orderBy('sent_at')->limit(20)]);

        $history = $conversation->messages->map(fn (Message $m) => [
            'role' => $m->direction === 'outbound' ? 'assistant' : 'user',
            'content' => $m->body_text ?? $m->snippet ?? '',
            'timestamp' => $m->sent_at?->toIso8601String(),
        ])->all();

        $trip = $conversation->trip;
        $tripContext = $trip ? [
            'trip_name' => $trip->trip_name,
            'status' => $trip->status->value,
            'start_date' => $trip->start_date?->toDateString(),
            'end_date' => $trip->end_date?->toDateString(),
            'budget' => $trip->budget,
        ] : null;

        try {
            $result = $ai->suggestResponse([
                'conversation_history' => $history,
                'trip_context' => $tripContext,
                'num_suggestions' => 3,
            ]);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['message' => 'Could not draft a suggestion right now.'], 502);
        }

        return response()->json($result);
    }
}
