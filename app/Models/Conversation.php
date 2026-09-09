<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Model for the `conversations` table.
 *
 * Purpose: A single thread on one channel (e.g. a Gmail thread), optionally matched to a
 * Customer/Trip. `external_thread_id` is the channel's own thread identifier (Gmail's
 * `threadId`), unique per (company_id, channel).
 *
 * @property string $conversation_id
 * @property string $company_id
 * @property string $channel
 * @property string|null $customer_id
 * @property string|null $trip_id
 * @property string $external_thread_id
 * @property string|null $subject
 * @property \Carbon\Carbon|null $last_message_at
 * @property int $unread_count
 */
class Conversation extends Model
{
    use HasFactory;

    protected $table = 'conversations';
    protected $primaryKey = 'conversation_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'conversation_id',
        'company_id',
        'channel',
        'customer_id',
        'trip_id',
        'external_thread_id',
        'subject',
        'last_message_at',
        'unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id', 'conversation_id');
    }

    // Most recent message only — lets the conversation list show a preview line without
    // loading every message in every thread (see ConversationController::index).
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id', 'conversation_id')->latestOfMany('sent_at');
    }
}
