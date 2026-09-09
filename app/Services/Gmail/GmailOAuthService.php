<?php

namespace App\Services\Gmail;

use App\Models\GmailAccount;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Gmail;
use RuntimeException;

/**
 * Builds the Google OAuth client used to connect a company's Google account (Gmail and/or
 * Calendar, both on one shared identity per company — see GmailAccount's docblock) and
 * exchanges the authorization code Google redirects back with for tokens.
 */
class GmailOAuthService
{
    // gmail.readonly + gmail.compose covers reading mail and creating/sending drafts without
    // the broader gmail.modify (arbitrary label/trash mutation) the integration doesn't need.
    private const GMAIL_SCOPES = [
        Gmail::GMAIL_READONLY,
        Gmail::GMAIL_COMPOSE,
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    // calendar.events covers both reading and creating events (needed for
    // CalendarWatcherJob's listing and CallController::scheduleWithMeet's creation) without the
    // broader, unneeded calendar.settings/acl scopes.
    private const CALENDAR_SCOPES = [
        Calendar::CALENDAR_EVENTS,
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    /** @return string[] */
    public function scopesFor(string $app): array
    {
        return match ($app) {
            'calendar' => self::CALENDAR_SCOPES,
            default => self::GMAIL_SCOPES,
        };
    }

    public function makeClient(array $scopes = []): Client
    {
        $client = new Client();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect_uri'));
        if (!empty($scopes)) {
            $client->setScopes($scopes);
        }
        // offline + consent is what makes Google actually send back a refresh_token — without
        // 'consent', a user who has already granted these scopes once gets silently re-approved
        // and Google omits the refresh_token from the response entirely.
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    /**
     * $incremental=true adds include_granted_scopes so a company adding Calendar after already
     * connecting Gmail (or vice versa) keeps both on the resulting token, without Google
     * re-prompting for whichever one was already granted.
     */
    public function getAuthUrl(string $state, array $scopes, bool $incremental = false): string
    {
        $client = $this->makeClient($scopes);
        $client->setState($state);

        return $client->createAuthUrl(null, $incremental ? ['include_granted_scopes' => 'true'] : []);
    }

    /**
     * Exchanges an OAuth authorization code for a token response
     * (access_token/refresh_token/expires_in/scope).
     */
    public function exchangeCode(string $code): array
    {
        $token = $this->makeClient()->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Google token exchange failed: ' . ($token['error_description'] ?? $token['error']));
        }

        return $token;
    }

    /**
     * Applies a Google token response onto a GmailAccount instance in-place (caller persists).
     * The one rule that matters here — never overwrite an existing refresh_token with null —
     * lives in exactly one place so both the OAuth callback and GmailService's refresh path
     * share it instead of duplicating (and risking divergent) logic.
     */
    public function applyTokenResponse(GmailAccount $account, array $token): void
    {
        $account->access_token = $token['access_token'];

        if (!empty($token['refresh_token'])) {
            $account->refresh_token = $token['refresh_token'];
        }

        $account->token_expires_at = now()->addSeconds((int) ($token['expires_in'] ?? 3600));

        if (!empty($token['scope'])) {
            $account->scopes = explode(' ', $token['scope']);
        }
    }
}
