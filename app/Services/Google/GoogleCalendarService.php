<?php

namespace App\Services\Google;

use App\Models\GmailAccount;
use App\Services\Gmail\GmailOAuthService;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Per-account authenticated Google Calendar access — same shared-identity-per-company shape as
 * GmailService, just a different Google API. Only ever touches the company's `primary`
 * calendar; there's no per-calendar selection in this product.
 *
 * The authenticated-client/token-refresh logic below is intentionally the same as
 * GmailService::authenticatedClient() (duplicated, not shared) — two call sites doesn't yet
 * justify pulling it into a common base; worth revisiting if a third Google API joins these two.
 */
class GoogleCalendarService
{
    private const CALENDAR_ID = 'primary';

    private Client $client;
    private Calendar $calendar;

    public function __construct(
        private readonly GmailAccount $account,
        private readonly GmailOAuthService $oauth,
    ) {
        $this->client = $this->authenticatedClient();
        $this->calendar = new Calendar($this->client);
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
            'created' => now()->timestamp,
            'expires_in' => $expiresIn,
        ]);

        if ($client->isAccessTokenExpired()) {
            if (!$this->account->refresh_token) {
                throw new RuntimeException("GmailAccount {$this->account->gmail_account_id} has no refresh_token — reconnection required.");
            }

            $token = $client->fetchAccessTokenWithRefreshToken($this->account->refresh_token);
            if (isset($token['error'])) {
                $this->account->status = 'revoked';
                $this->account->save();
                throw new RuntimeException('Google token refresh failed: ' . ($token['error_description'] ?? $token['error']));
            }

            $this->oauth->applyTokenResponse($this->account, $token);
            $this->account->save();
            $client->setAccessToken($client->getAccessToken());
        }

        return $client;
    }

    /**
     * Upcoming events on the primary calendar with a Google Meet link, in the next
     * $lookaheadMinutes. Used by CalendarWatcherJob to find calls worth tracking.
     *
     * @return array<array{event_id: string, summary: ?string, start: ?string, attendee_emails: string[], hangout_link: string}>
     */
    public function listUpcomingMeetEvents(int $lookaheadMinutes = 30): array
    {
        $response = $this->calendar->events->listEvents(self::CALENDAR_ID, [
            'timeMin' => now()->toRfc3339String(),
            'timeMax' => now()->addMinutes($lookaheadMinutes)->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
        ]);

        $events = [];
        foreach ($response->getItems() ?? [] as $event) {
            if (!$event->getHangoutLink()) {
                continue;
            }

            $events[] = [
                'event_id' => $event->getId(),
                'summary' => $event->getSummary(),
                'start' => $event->getStart()?->getDateTime(),
                'attendee_emails' => array_values(array_filter(array_map(
                    fn (EventAttendee $a) => $a->getEmail(),
                    $event->getAttendees() ?? []
                ))),
                'hangout_link' => $event->getHangoutLink(),
            ];
        }

        return $events;
    }

    /**
     * Creates a new event with an auto-generated Google Meet link (CallController::scheduleWithMeet).
     *
     * @param string[] $attendeeEmails
     * @return array{event_id: string, hangout_link: ?string}
     */
    public function createMeetEvent(string $summary, \DateTimeInterface $start, \DateTimeInterface $end, array $attendeeEmails): array
    {
        $event = new Event();
        $event->setSummary($summary);

        $eventStart = new EventDateTime();
        $eventStart->setDateTime($start->format(\DateTimeInterface::RFC3339));
        $event->setStart($eventStart);

        $eventEnd = new EventDateTime();
        $eventEnd->setDateTime($end->format(\DateTimeInterface::RFC3339));
        $event->setEnd($eventEnd);

        $event->setAttendees(array_map(function (string $email) {
            $attendee = new EventAttendee();
            $attendee->setEmail($email);

            return $attendee;
        }, $attendeeEmails));

        $solutionKey = new ConferenceSolutionKey();
        $solutionKey->setType('hangoutsMeet');

        $createRequest = new CreateConferenceRequest();
        $createRequest->setRequestId(Str::uuid()->toString());
        $createRequest->setConferenceSolutionKey($solutionKey);

        $conferenceData = new ConferenceData();
        $conferenceData->setCreateRequest($createRequest);
        $event->setConferenceData($conferenceData);

        $created = $this->calendar->events->insert(self::CALENDAR_ID, $event, ['conferenceDataVersion' => 1]);

        return [
            'event_id' => $created->getId(),
            'hangout_link' => $created->getHangoutLink(),
        ];
    }
}
