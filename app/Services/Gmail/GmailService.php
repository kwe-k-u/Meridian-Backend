<?php

namespace App\Services\Gmail;

use App\Models\GmailAccount;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Draft;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\Profile;
use Illuminate\Support\Str;

/**
 * Per-account authenticated Gmail API access. One instance wraps one company's connected
 * mailbox (GmailAccount) — the access token is refreshed transparently and the refreshed
 * token/expiry is persisted back onto the model before any API call that needs it.
 */
class GmailService
{
    private Client $client;
    private Gmail $gmail;

    public function __construct(
        private readonly GmailAccount $account,
        private readonly GmailOAuthService $oauth,
    ) {
        $this->client = $this->authenticatedClient();
        $this->gmail = new Gmail($this->client);
    }

    private function authenticatedClient(): Client
    {
        $expiresIn = 0;
        if ($this->account->token_expires_at && $this->account->token_expires_at->isFuture()) {
            $expiresIn = now()->diffInSeconds($this->account->token_expires_at);
        }

        $client = $this->oauth->makeClient();
        $client->setAccessToken([
            'access_token' => $this->account->access_token,
            'refresh_token' => $this->account->refresh_token,
            // 'created' must be set explicitly — isAccessTokenExpired() falls back to created=0
            // (epoch) when absent, which would make it treat every token as expired and force a
            // refresh on every single call.
            'created' => now()->timestamp,
            'expires_in' => $expiresIn,
        ]);

        if ($client->isAccessTokenExpired()) {
            if (!$this->account->refresh_token) {
                throw new \RuntimeException("GmailAccount {$this->account->gmail_account_id} has no refresh_token — reconnection required.");
            }

            $token = $client->fetchAccessTokenWithRefreshToken($this->account->refresh_token);
            if (isset($token['error'])) {
                // invalid_grant here almost always means the refresh token was revoked (user
                // revoked access, or — in Google's "Testing" OAuth consent status — it simply
                // expired after 7 days) — surface that via status rather than failing silently
                // on every subsequent poll.
                $this->account->status = 'revoked';
                $this->account->save();
                throw new \RuntimeException('Gmail token refresh failed: ' . ($token['error_description'] ?? $token['error']));
            }

            $this->oauth->applyTokenResponse($this->account, $token);
            $this->account->save();
            $client->setAccessToken($client->getAccessToken());
        }

        return $client;
    }

    public function getProfile(): Profile
    {
        return $this->gmail->users->getProfile('me');
    }

    /**
     * One page of the mailbox's threads, optionally filtered by a Gmail search query (same
     * syntax as the Gmail search box). Used to populate the "add a thread" picker — each
     * result already carries a snippet (the latest message's), but not subject/sender; see
     * getThreadMeta() for that, which the caller runs once per result — kept deliberately small
     * (Gmail defaults to 100 per page, which at one metadata call per thread took ~40s in
     * practice and silently failed against PHP's execution time limit).
     *
     * @return array{threads: array<array{id: string, snippet: string}>, nextPageToken: ?string}
     */
    public function listThreads(?string $query = null, ?string $pageToken = null, int $maxResults = 15): array
    {
        $response = $this->gmail->users_threads->listUsersThreads('me', array_filter([
            'q' => $query,
            'pageToken' => $pageToken,
            'maxResults' => $maxResults,
        ]));

        $threads = array_map(fn ($t) => [
            'id' => $t->getId(),
            'snippet' => $t->getSnippet(),
        ], $response->getThreads() ?? []);

        return ['threads' => $threads, 'nextPageToken' => $response->getNextPageToken()];
    }

    /**
     * Cheap header-only preview of a thread (no message bodies) — subject/from/date off its
     * most recent message, for filling in a picker row.
     *
     * @return array{subject: ?string, from_name: ?string, from_email: ?string, date: ?string}
     */
    public function getThreadMeta(string $threadId): array
    {
        $thread = $this->gmail->users_threads->get('me', $threadId, [
            'format' => 'metadata',
            'metadataHeaders' => ['From', 'Subject', 'Date'],
        ]);

        $messages = $thread->getMessages() ?? [];
        $last = end($messages) ?: null;
        $headers = [];
        foreach ($last?->getPayload()?->getHeaders() ?? [] as $header) {
            $headers[strtolower($header->getName())] = $header->getValue();
        }

        [$fromName, $fromEmail] = $this->parseFromHeader($headers['from'] ?? '');

        return [
            'subject' => $headers['subject'] ?? null,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'date' => $headers['date'] ?? null,
        ];
    }

    // Small, deliberately duplicated from GmailMessageIngester's identical parser — that class
    // owns the ingestion pipeline, this one owns thin Gmail API access; not worth a cross-class
    // dependency for one regex.
    /** @return array{0: ?string, 1: ?string} [name, email] */
    private function parseFromHeader(string $from): array
    {
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/', $from, $m)) {
            return [trim($m[1]) ?: null, trim($m[2])];
        }
        $email = trim($from);

        return [null, $email !== '' ? $email : null];
    }

    /** Full messages for one thread, in chronological order. */
    public function getThread(string $threadId): array
    {
        $thread = $this->gmail->users_threads->get('me', $threadId, ['format' => 'full']);

        return $thread->getMessages() ?? [];
    }

    public function createDraft(?string $threadId, string $to, string $subject, string $bodyText): Draft
    {
        $message = new GmailMessage();
        $message->setRaw($this->buildRawMessage($to, $subject, $bodyText)['raw']);
        if ($threadId) {
            $message->setThreadId($threadId);
        }

        $draft = new Draft();
        $draft->setMessage($message);

        return $this->gmail->users_drafts->create('me', $draft);
    }

    /**
     * Actually sends (not drafts) a message — used by ConversationController::sendMessage() for
     * real inbox replies. Setting threadId alone only groups the message in the *sender's own*
     * Gmail account; for Gmail (and every other client) to actually render it as part of the
     * same conversation, the raw message also needs In-Reply-To/References headers chaining back
     * to the parent message's Message-ID — pass those in for anything that isn't the first
     * message in a thread.
     *
     * Gmail's send response doesn't reliably echo back the Message-ID header, so this generates
     * one itself and returns it alongside the API result — the caller should persist it as the
     * stored message's rfc_message_id so the *next* reply can chain off of it.
     *
     * @return array{message: GmailMessage, messageId: string}
     */
    public function sendMessage(
        ?string $threadId,
        string $to,
        string $subject,
        string $bodyText,
        ?string $inReplyTo = null,
        ?string $references = null,
    ): array {
        $built = $this->buildRawMessage($to, $subject, $bodyText, $inReplyTo, $references);

        $message = new GmailMessage();
        $message->setRaw($built['raw']);
        if ($threadId) {
            $message->setThreadId($threadId);
        }

        $sent = $this->gmail->users_messages->send('me', $message);

        return ['message' => $sent, 'messageId' => $built['messageId']];
    }

    /** @return array{raw: string, messageId: string} */
    private function buildRawMessage(
        string $to,
        string $subject,
        string $bodyText,
        ?string $inReplyTo = null,
        ?string $references = null,
    ): array {
        $domain = explode('@', $this->account->google_email)[1] ?? 'meridian.app';
        $messageId = sprintf('<%s@%s>', (string) Str::uuid(), $domain);

        $rawLines = [
            'To: ' . $to,
            'Subject: ' . $subject,
            'Message-ID: ' . $messageId,
        ];
        if ($inReplyTo) {
            $rawLines[] = 'In-Reply-To: ' . $inReplyTo;
        }
        if ($references) {
            $rawLines[] = 'References: ' . $references;
        }
        $rawLines[] = 'Content-Type: text/plain; charset="UTF-8"';
        $rawLines[] = '';
        $rawLines[] = $bodyText;

        $raw = rtrim(strtr(base64_encode(implode("\r\n", $rawLines)), '+/', '-_'), '=');

        return ['raw' => $raw, 'messageId' => $messageId];
    }
}
