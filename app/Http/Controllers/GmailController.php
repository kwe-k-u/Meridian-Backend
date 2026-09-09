<?php

namespace App\Http\Controllers;

use App\Helpers\UserHelper;
use App\Models\GmailAccount;
use App\Services\Gmail\GmailOAuthService;
use App\Services\IdGeneratorService;
use Google\Service\Gmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Google OAuth connect/disconnect + status for Settings > Channels — Gmail and Calendar share
 * one connected identity per company (see GmailAccount's docblock) but are enabled/disabled
 * independently via the `app` query param (`gmail` | `calendar`, default `gmail`). Meet call
 * tracking is a further toggle on top of Calendar (same scope, no extra OAuth round-trip) —
 * see updateMeetTracking().
 *
 * Routes: GET /api/gmail/connect, GET /api/gmail/callback (public), GET /api/gmail/status,
 * POST /api/gmail/disconnect, PATCH /api/gmail/meet-tracking.
 *
 * The connect/callback split exists because a Sanctum Bearer token can't ride along on a real
 * browser redirect: /gmail/connect is called by the frontend as an authenticated XHR and just
 * returns the Google auth URL as JSON; the frontend itself navigates the browser there. Google
 * then redirects the browser to /gmail/callback directly — a fresh, unauthenticated hop — so
 * that route has to be public and instead trusts a short-lived, single-use `state` value this
 * controller cached at connect time to recover which company (and which app) initiated the flow.
 */
class GmailController extends Controller
{
    private const STATE_CACHE_PREFIX = 'gmail_oauth_state:';
    private const STATE_TTL_MINUTES = 10;
    private const APPS = ['gmail', 'calendar'];

    public function __construct(private readonly GmailOAuthService $oauth) {}

    private function resolveApp(Request $request): string
    {
        $app = $request->query('app', 'gmail');

        return in_array($app, self::APPS, true) ? $app : 'gmail';
    }

    // GET /api/gmail/connect?app=gmail|calendar
    public function connect(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $app = $this->resolveApp($request);
        $state = Str::random(40);

        // Incremental auth: a company that already connected one app and is now adding the
        // other keeps both scopes on the resulting token instead of Google re-prompting for
        // whatever was already granted (see GmailOAuthService::getAuthUrl()).
        $incremental = GmailAccount::where('company_id', $company->company_id)->exists();

        Cache::put(self::STATE_CACHE_PREFIX . $state, [
            'company_id' => $company->company_id,
            'user_id' => $request->user()->user_id,
            'app' => $app,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        $url = $this->oauth->getAuthUrl($state, $this->oauth->scopesFor($app), $incremental);

        return response()->json(['url' => $url]);
    }

    // GET /api/gmail/callback — public; see class docblock.
    public function callback(Request $request): RedirectResponse
    {
        // Reuses the same shared frontend-base-url config Moolre's redirect flows already read
        // from (config/services.php's 'moolre.frontend_url', backed by FRONTEND_URL) rather
        // than introducing a second config key for the same value.
        $channelsUrl = rtrim(config('services.moolre.frontend_url'), '/') . '/app/settings/channels';

        if ($request->filled('error') || !$request->filled('code') || !$request->filled('state')) {
            return redirect()->away($channelsUrl . '?gmail=error');
        }

        $stateKey = self::STATE_CACHE_PREFIX . $request->query('state');
        $stateData = Cache::get($stateKey);
        Cache::forget($stateKey);

        if (!$stateData) {
            return redirect()->away($channelsUrl . '?gmail=error');
        }

        try {
            $token = $this->oauth->exchangeCode($request->query('code'));

            $client = $this->oauth->makeClient();
            $client->setAccessToken($token);
            $gmail = new Gmail($client);
            $profile = $gmail->users->getProfile('me');

            $existing = GmailAccount::where('company_id', $stateData['company_id'])->first();

            // A reconnect with the SAME mailbox should keep polling from where it left off; a
            // reconnect with a DIFFERENT mailbox must not inherit the old one's refresh_token,
            // history_id, sync cursor, or app-enabled flags — those belong to a different
            // inbox entirely, and neither app should be considered enabled on it until this
            // flow (or a future one) actually grants it.
            if ($existing && $existing->google_email !== $profile->getEmailAddress()) {
                $existing->history_id = null;
                $existing->last_synced_at = null;
                $existing->refresh_token = null;
                $existing->gmail_enabled = false;
                $existing->calendar_enabled = false;
                $existing->meet_tracking_enabled = false;
            }

            $account = $existing ?? new GmailAccount([
                'gmail_account_id' => IdGeneratorService::generateId('GML'),
                'company_id' => $stateData['company_id'],
            ]);

            $account->connected_by = $stateData['user_id'];
            $account->google_email = $profile->getEmailAddress();
            $account->status = 'active';
            $this->oauth->applyTokenResponse($account, $token);

            $app = in_array($stateData['app'] ?? null, self::APPS, true) ? $stateData['app'] : 'gmail';
            $account->{$app . '_enabled'} = true;

            $account->save();

            return redirect()->away($channelsUrl . '?gmail=connected');
        } catch (Throwable $e) {
            report($e);

            return redirect()->away($channelsUrl . '?gmail=error');
        }
    }

    // GET /api/gmail/status
    public function status(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $account = GmailAccount::where('company_id', $company->company_id)->first();

        if (!$account) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected' => $account->status === 'active',
            'google_email' => $account->google_email,
            'status' => $account->status,
            'last_synced_at' => $account->last_synced_at,
            'gmail_enabled' => $account->gmail_enabled,
            'calendar_enabled' => $account->calendar_enabled,
            'meet_tracking_enabled' => $account->meet_tracking_enabled,
        ]);
    }

    // PATCH /api/gmail/meet-tracking — body: { enabled: bool }. Toggles Meet call
    // auto-detection/scheduling on top of an already-connected Calendar — no OAuth round-trip,
    // since it's the same calendar.events scope Calendar already granted.
    public function updateMeetTracking(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $account = GmailAccount::where('company_id', $company->company_id)->first();

        $validated = $request->validate(['enabled' => 'required|boolean']);

        if ($validated['enabled'] && (!$account || !$account->calendar_enabled)) {
            return response()->json(['message' => 'Connect Google Calendar first.'], 422);
        }

        $account->meet_tracking_enabled = $validated['enabled'];
        $account->save();

        return response()->json(['meet_tracking_enabled' => $account->meet_tracking_enabled]);
    }

    // POST /api/gmail/disconnect?app=gmail|calendar
    public function disconnect(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $app = $this->resolveApp($request);
        $account = GmailAccount::where('company_id', $company->company_id)->first();

        if (!$account) {
            return response()->json(['message' => 'Nothing connected.'], 404);
        }

        $otherApp = $app === 'gmail' ? 'calendar' : 'gmail';
        $otherEnabled = $account->{$otherApp . '_enabled'};

        if ($otherEnabled) {
            // The other app still needs this token — Google doesn't support revoking a single
            // scope off an existing token, so the honest thing to do is stop Meridian from
            // using this app rather than pretend the underlying grant shrank.
            $account->{$app . '_enabled'} = false;
            if ($app === 'calendar') {
                // Meet tracking can't function without calendar access — cascade it off rather
                // than leaving a stale "enabled" flag pointing at a disabled feature.
                $account->meet_tracking_enabled = false;
            }
            $account->save();

            return response()->json(['message' => ucfirst($app) . ' disabled.']);
        }

        // Last enabled app — fully disconnect: best-effort revoke (Google access should stop
        // immediately even if this call fails, e.g. token already invalid) then delete the row.
        try {
            $client = $this->oauth->makeClient();
            $client->revokeToken($account->refresh_token ?? $account->access_token);
        } catch (Throwable $e) {
            report($e);
        }

        $account->delete();

        return response()->json(['message' => 'Google account disconnected.']);
    }
}
