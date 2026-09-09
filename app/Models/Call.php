<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `calls` table.
 *
 * Purpose: Represents a planning call or meeting organized for a trip, including transcript and action items.
 *
 * @property string $call_id Unique identifier for the call.
 * @property string $trip_id Foreign key to the associated trip.
 * @property string $organized_by Foreign key to the user who organized the call.
 * @property \Carbon\Carbon|null $started_at When the call started.
 * @property \Carbon\Carbon|null $ended_at When the call ended.
 * @property string|null $google_event_id Set when this call was created by/detected from the
 *     company's connected Google Calendar (CalendarWatcherJob or CallController::scheduleWithMeet)
 *     — null for a manually logged call with no Calendar backing.
 * @property bool $excluded When true, CalendarWatcherJob leaves this call alone (no future
 *     auto-tracking) even though it still originated from a Calendar event.
 */
class Call extends Model
{
    use HasFactory;

    protected $table = 'calls';
    protected $primaryKey = 'call_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'call_id',
        'trip_id',
        'organized_by',
        'title',
        'started_at',
        'ended_at',
        'meeting_link',
        'notes',
        'transcript',
        'google_event_id',
        'excluded',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'excluded' => 'boolean',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function organizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organized_by', 'user_id');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(CallActionItem::class, 'call_id', 'call_id');
    }
}
