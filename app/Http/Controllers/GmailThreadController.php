<?php

namespace App\Http\Controllers;

use App\Helpers\UserHelper;
use App\Models\Conversation;
use App\Models\GmailAccount;
use App\Services\Gmail\GmailMessageIngester;
use App\Services\Gmail\GmailOAuthService;
use App\Services\Gmail\GmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets an agent browse their connected Gmail mailbox and pick specific threads to track in
 * Meridian, instead of every message being synced automatically. This is deliberate: a shared
 * agency inbox gets newsletters, receipts, and unrelated mail alongside real traveler
 * correspondence, and PollGmailAccountJob has no way to tell those apart on its own — so
 * nothing is tracked until a human says so here. Once added, a thread stays synced by
 * PollGmailAccountJob going forward (new replies, not new threads).
 *
 * Routes: GET /api/gmail/threads/browse, POST /api/gmail/threads
 */
class GmailThreadController extends Controller
{
    public function __construct(private readonly GmailOAuthService $oauth) {}

    // GET /api/gmail/threads/browse?q=&page_token= — candidate threads not already tracked.
    public function browse(Request $request): JsonResponse
    {
        $account = $this->activeAccount($request);
        if (!$account) {
            return response()->json(['message' => 'Connect Gmail in Settings first.'], 422);
        }

        $gmail = new GmailService($account, $this->oauth);
        $page = $gmail->listThreads($request->query('q') ?: null, $request->query('page_token') ?: null);

        $trackedIds = Conversation::where('company_id', $account->company_id)
            ->where('channel', 'gmail')
            ->pluck('external_thread_id')
            ->all();

        $candidates = array_values(array_filter($page['threads'], fn ($t) => !in_array($t['id'], $trackedIds, true)));

        $threads = array_map(function ($t) use ($gmail) {
            $meta = $gmail->getThreadMeta($t['id']);
            return [
                'thread_id' => $t['id'],
                'subject' => $meta['subject'],
                'from_name' => $meta['from_name'],
                'from_email' => $meta['from_email'],
                'date' => $meta['date'],
                'snippet' => $t['snippet'],
            ];
        }, $candidates);

        return response()->json([
            'threads' => $threads,
            'next_page_token' => $page['nextPageToken'],
        ]);
    }

    // POST /api/gmail/threads — body: { thread_id }. Idempotent: re-adding an already-tracked
    // thread just returns it rather than erroring or duplicating.
    public function store(Request $request): JsonResponse
    {
        $account = $this->activeAccount($request);
        if (!$account) {
            return response()->json(['message' => 'Connect Gmail in Settings first.'], 422);
        }

        $validated = $request->validate(['thread_id' => 'required|string']);

        $existing = Conversation::where('company_id', $account->company_id)
            ->where('channel', 'gmail')
            ->where('external_thread_id', $validated['thread_id'])
            ->first();

        if ($existing) {
            return response()->json($existing->load(['customer', 'trip']));
        }

        $gmail = new GmailService($account, $this->oauth);
        $messages = $gmail->getThread($validated['thread_id']);

        $ingester = new GmailMessageIngester($account->company_id, $account->google_email);
        $conversation = $ingester->ingestNewThread($validated['thread_id'], $messages);

        return response()->json($conversation->load(['customer', 'trip', 'messages']));
    }

    private function activeAccount(Request $request): ?GmailAccount
    {
        $company = UserHelper::user_company($request);

        return GmailAccount::where('company_id', $company->company_id)
            ->where('status', 'active')
            ->first();
    }
}
