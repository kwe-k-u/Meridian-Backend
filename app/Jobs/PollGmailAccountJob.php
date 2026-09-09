<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\GmailAccount;
use App\Services\Gmail\GmailMessageIngester;
use App\Services\Gmail\GmailOAuthService;
use App\Services\Gmail\GmailService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refreshes every Gmail thread this company has explicitly opted into tracking (see
 * GmailThreadController — nothing is tracked automatically). For each already-tracked
 * conversation, re-fetches its thread and ingests any messages not already stored. Does NOT
 * discover new threads on its own; that's a deliberate choice — see GmailThreadController's
 * docblock for why.
 *
 * Dispatched every 2 minutes per active GmailAccount (see routes/console.php). Made unique
 * in-flight (ShouldBeUnique, keyed on the account id) because the scheduler's own
 * withoutOverlapping() only guards the scheduled *task* — it doesn't stop a slow run for one
 * account (large backlog) from overlapping the next tick's dispatch for that same account.
 */
class PollGmailAccountJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(private readonly GmailAccount $account) {}

    public function uniqueId(): string
    {
        return $this->account->gmail_account_id;
    }

    public function handle(GmailOAuthService $oauth): void
    {
        if ($this->account->status !== 'active') {
            return;
        }

        $gmail = new GmailService($this->account, $oauth);
        $ingester = new GmailMessageIngester($this->account->company_id, $this->account->google_email);

        $tracked = Conversation::where('company_id', $this->account->company_id)
            ->where('channel', 'gmail')
            ->get();

        foreach ($tracked as $conversation) {
            $ingester->ingestIntoExisting($conversation, $gmail->getThread($conversation->external_thread_id));
        }

        $this->account->last_synced_at = now();
        $this->account->save();
    }
}
