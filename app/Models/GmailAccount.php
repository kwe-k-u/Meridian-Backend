<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `gmail_accounts` table.
 *
 * Purpose: One connected Google identity per company (shared agency account), holding the
 * OAuth tokens used by GmailService/GoogleCalendarService. A single row can power more than
 * one Google product — gmail_enabled/calendar_enabled track which ones this company has
 * actually turned on, independent of what scopes the underlying token happens to carry (see
 * GmailController::disconnect() for why those aren't the same thing).
 *
 * @property string $gmail_account_id
 * @property string $company_id
 * @property string|null $connected_by
 * @property string $google_email
 * @property string $access_token
 * @property string|null $refresh_token
 * @property \Carbon\Carbon|null $token_expires_at
 * @property array|null $scopes
 * @property string|null $history_id Unused since Gmail sync moved to per-thread opt-in
 *     (GmailThreadController/PollGmailAccountJob) — kept in the schema rather than migrating
 *     out one nullable column.
 * @property \Carbon\Carbon|null $last_synced_at
 * @property string $status
 * @property bool $gmail_enabled
 * @property bool $calendar_enabled
 * @property bool $meet_tracking_enabled Feature toggle on top of calendar_enabled — same OAuth
 *     scope, but a company can have calendar visibility without opting into Meet call
 *     auto-detection/scheduling (CalendarWatcherJob/CallController::scheduleWithMeet both
 *     require this in addition to calendar_enabled).
 */
class GmailAccount extends Model
{
    use HasFactory;

    protected $table = 'gmail_accounts';
    protected $primaryKey = 'gmail_account_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'gmail_account_id',
        'company_id',
        'connected_by',
        'google_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'history_id',
        'last_synced_at',
        'status',
        'gmail_enabled',
        'calendar_enabled',
        'meet_tracking_enabled',
    ];

    protected $casts = [
        // Encrypted at rest via Laravel's Eloquent 'encrypted' cast (keyed off APP_KEY) —
        // tokens are never stored or logged in plaintext.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'scopes' => 'array',
        'last_synced_at' => 'datetime',
        'gmail_enabled' => 'boolean',
        'calendar_enabled' => 'boolean',
        'meet_tracking_enabled' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by', 'user_id');
    }
}
