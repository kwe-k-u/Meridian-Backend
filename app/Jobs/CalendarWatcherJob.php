<?php

namespace App\Jobs;

use App\Enums\TripStatus;
use App\Models\Call;
use App\Models\Customer;
use App\Models\GmailAccount;
use App\Services\Gmail\GmailOAuthService;
use App\Services\Google\GoogleCalendarService;
use App\Services\IdGeneratorService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Watches one company's connected Google Calendar for upcoming Meet calls and lands the
 * unambiguous ones as `Call` rows. Deliberately narrow, matching Gmail's own opt-in philosophy:
 * an event only becomes a Call when an attendee matches an existing Customer who has exactly
 * one non-completed trip — anything else (unknown attendee, multiple active trips) is skipped
 * rather than guessed at. There's no "unmatched calendar events" triage queue yet (see the plan
 * this shipped under) — that's future work, same as the bot/transcription pieces.
 *
 * Dispatched every 5 minutes per active GmailAccount with both calendar_enabled and
 * meet_tracking_enabled (routes/console.php) — Calendar access alone doesn't imply Meet
 * tracking is wanted, that's a separate toggle (Settings > Channels' Google Meet card). Unique
 * in-flight per account for the same reason PollGmailAccountJob is.
 */
class CalendarWatcherJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    private const LOOKAHEAD_MINUTES = 30;

    public function __construct(private readonly GmailAccount $account) {}

    public function uniqueId(): string
    {
        return $this->account->gmail_account_id;
    }

    public function handle(GmailOAuthService $oauth): void
    {
        if ($this->account->status !== 'active' || !$this->account->calendar_enabled || !$this->account->meet_tracking_enabled) {
            return;
        }

        $calendar = new GoogleCalendarService($this->account, $oauth);

        foreach ($calendar->listUpcomingMeetEvents(self::LOOKAHEAD_MINUTES) as $event) {
            $this->ingestEvent($event);
        }
    }

    /** @param array{event_id: string, summary: ?string, start: ?string, attendee_emails: string[], hangout_link: string} $event */
    private function ingestEvent(array $event): void
    {
        $alreadyTracked = Call::where('google_event_id', $event['event_id'])->exists();
        if ($alreadyTracked) {
            return;
        }

        $customer = $this->matchCustomer($event['attendee_emails']);
        if (!$customer) {
            return;
        }

        $activeTrips = $customer->trips()->where('status', '!=', TripStatus::COMPLETED->value)->get();
        if ($activeTrips->count() !== 1) {
            return;
        }

        Call::create([
            'call_id' => IdGeneratorService::generateId('CAL'),
            'trip_id' => $activeTrips->first()->trip_id,
            'title' => $event['summary'] ?: 'Google Meet call',
            'started_at' => $event['start'],
            'meeting_link' => $event['hangout_link'],
            'google_event_id' => $event['event_id'],
        ]);
    }

    /** @param string[] $attendeeEmails */
    private function matchCustomer(array $attendeeEmails): ?Customer
    {
        foreach ($attendeeEmails as $email) {
            $customer = Customer::where('company_id', $this->account->company_id)
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->first();

            if ($customer) {
                return $customer;
            }
        }

        return null;
    }
}
