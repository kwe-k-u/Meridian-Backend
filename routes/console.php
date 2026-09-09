<?php

// ── Console Artisan Commands ──
// Define custom Artisan commands and scheduled tasks here.

use App\Jobs\CalendarWatcherJob;
use App\Jobs\PollGmailAccountJob;
use App\Models\GmailAccount;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Polls every connected, active Gmail mailbox for new mail (see PollGmailAccountJob). Each
// dispatch is uniqued on the account id, so a slow run for one account can't overlap the next
// tick's dispatch for that same account — this requires `php artisan schedule:work` (or a real
// cron calling `schedule:run`) plus a queue worker (`php artisan queue:listen`) both running.
Schedule::call(function () {
    GmailAccount::where('status', 'active')->get()->each(
        fn (GmailAccount $account) => PollGmailAccountJob::dispatch($account)
    );
})->everyTwoMinutes();

// Watches every connected, active Google account with Meet tracking turned on for upcoming
// Meet calls (see CalendarWatcherJob). Filtering here (rather than inside the job) avoids even
// instantiating a job for companies that have Calendar connected but never turned Meet tracking
// on — those are two separate toggles now (Settings > Channels' Google Calendar vs Google Meet
// cards).
Schedule::call(function () {
    GmailAccount::where('status', 'active')
        ->where('calendar_enabled', true)
        ->where('meet_tracking_enabled', true)
        ->get()
        ->each(fn (GmailAccount $account) => CalendarWatcherJob::dispatch($account));
})->everyFiveMinutes();
