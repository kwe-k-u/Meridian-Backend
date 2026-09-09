<?php

namespace App\Services\Gmail;

use App\Enums\TripStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Services\IdGeneratorService;
use Carbon\Carbon;
use Google\Service\Gmail\Message as GmailApiMessage;
use Google\Service\Gmail\MessagePart;

/**
 * Parses Gmail API messages into Conversation/Message rows. Shared by two callers that both
 * need the same header/body parsing but differ in whether the Conversation already exists:
 * PollGmailAccountJob (refreshing an already-tracked thread) and GmailThreadController::store()
 * (tracking a thread for the first time, via the "add a Gmail thread" picker).
 */
class GmailMessageIngester
{
    public function __construct(private readonly string $companyId, private readonly string $googleEmail) {}

    /** Applies any not-yet-stored messages onto an already-tracked conversation. */
    public function ingestIntoExisting(Conversation $conversation, array $messages): void
    {
        foreach ($messages as $message) {
            $this->applyMessage($conversation, $message);
        }
    }

    /**
     * Creates the Conversation row for a thread the agent just opted into, then ingests every
     * message in it. Customer/trip matching runs off the first message's sender, the same rule
     * PollGmailAccountJob has always used for a brand-new thread.
     */
    public function ingestNewThread(string $threadId, array $messages): Conversation
    {
        $first = $messages[0] ?? null;
        $headers = $this->headers($first?->getPayload());
        [, $fromEmail] = $this->parseFromHeader($headers['from'] ?? '');

        $customer = $fromEmail
            ? Customer::where('company_id', $this->companyId)
                ->whereRaw('LOWER(email) = ?', [strtolower($fromEmail)])
                ->first()
            : null;

        $conversation = new Conversation([
            'conversation_id' => IdGeneratorService::generateId('CNV'),
            'company_id' => $this->companyId,
            'channel' => 'gmail',
            'external_thread_id' => $threadId,
            'subject' => $headers['subject'] ?? null,
            'customer_id' => $customer?->customer_id,
            'trip_id' => $customer ? $this->soleActiveTripId($customer) : null,
            'unread_count' => 0,
        ]);
        $conversation->save();

        $this->ingestIntoExisting($conversation, $messages);

        return $conversation->fresh();
    }

    private function applyMessage(Conversation $conversation, GmailApiMessage $message): void
    {
        $alreadyStored = Message::where('conversation_id', $conversation->conversation_id)
            ->where('external_message_id', $message->getId())
            ->exists();

        if ($alreadyStored) {
            return;
        }

        $headers = $this->headers($message->getPayload());
        [$fromName, $fromEmail] = $this->parseFromHeader($headers['from'] ?? '');
        $sentAt = $message->getInternalDate()
            ? Carbon::createFromTimestampMs((int) $message->getInternalDate())
            : now();
        $direction = $fromEmail && strcasecmp($fromEmail, $this->googleEmail) === 0
            ? 'outbound'
            : 'inbound';

        Message::create([
            'message_id' => IdGeneratorService::generateId('MSG'),
            'conversation_id' => $conversation->conversation_id,
            'external_message_id' => $message->getId(),
            'rfc_message_id' => $headers['message-id'] ?? null,
            'rfc_references' => $headers['references'] ?? null,
            'direction' => $direction,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'body_text' => $this->stripQuotedReply($this->plainTextBody($message->getPayload())),
            'snippet' => $message->getSnippet(),
            'sent_at' => $sentAt,
        ]);

        if (!$conversation->last_message_at || $sentAt->gt($conversation->last_message_at)) {
            $conversation->last_message_at = $sentAt;
        }
        if ($direction === 'inbound') {
            $conversation->unread_count = ($conversation->unread_count ?? 0) + 1;
        }
        $conversation->save();
    }

    /** Only auto-links a trip when the match is unambiguous; otherwise an agent triages it. */
    private function soleActiveTripId(Customer $customer): ?string
    {
        $activeTrips = $customer->trips()->where('status', '!=', TripStatus::COMPLETED->value)->get();

        return $activeTrips->count() === 1 ? $activeTrips->first()->trip_id : null;
    }

    /** @return array<string,string> lower-cased header name => value */
    private function headers(?MessagePart $payload): array
    {
        $headers = [];
        foreach ($payload?->getHeaders() ?? [] as $header) {
            $headers[strtolower($header->getName())] = $header->getValue();
        }

        return $headers;
    }

    /** @return array{0: ?string, 1: ?string} [name, email] */
    private function parseFromHeader(string $from): array
    {
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/', $from, $m)) {
            return [trim($m[1]) ?: null, trim($m[2])];
        }
        $email = trim($from);

        return [null, $email !== '' ? $email : null];
    }

    /** Walks the MIME part tree for a text/plain body, falling back to tag-stripped text/html. */
    private function plainTextBody(?MessagePart $part, ?string $htmlFallback = null): string
    {
        if (!$part) {
            return $htmlFallback !== null ? strip_tags($htmlFallback) : '';
        }

        if ($part->getMimeType() === 'text/plain' && $part->getBody()?->getData()) {
            return $this->decodeBody($part->getBody()->getData());
        }

        if ($part->getMimeType() === 'text/html' && $part->getBody()?->getData()) {
            $htmlFallback ??= $this->decodeBody($part->getBody()->getData());
        }

        foreach ($part->getParts() ?? [] as $child) {
            $found = $this->plainTextBody($child, $htmlFallback);
            if ($found !== '') {
                return $found;
            }
        }

        return $htmlFallback !== null ? strip_tags($htmlFallback) : '';
    }

    private function decodeBody(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Best-effort quoted-reply/signature trim (v1 heuristic, not exhaustive) — cuts the body at
     * the first line that looks like a reply-quote marker, so stored message text doesn't
     * balloon with the entire thread's history on every reply.
     */
    private function stripQuotedReply(string $body): string
    {
        $markers = [
            '/^\s*On .+ wrote:\s*$/mi',
            '/^-{2,}\s*Original Message\s*-{2,}/mi',
            '/^>.*$/m',
        ];

        foreach ($markers as $pattern) {
            if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE)) {
                $body = substr($body, 0, $m[0][1]);
            }
        }

        return trim($body);
    }
}
