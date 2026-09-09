<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `messages` table.
 *
 * Purpose: One message within a Conversation. `external_message_id` (Gmail's message id) is
 * unique per conversation, which is what makes PollGmailAccountJob's upsert idempotent across
 * overlapping poll windows.
 *
 * @property string $message_id
 * @property string $conversation_id
 * @property string|null $external_message_id
 * @property string|null $rfc_message_id
 * @property string|null $rfc_references
 * @property string $direction
 * @property string|null $from_email
 * @property string|null $from_name
 * @property string|null $body_text
 * @property string|null $snippet
 * @property \Carbon\Carbon|null $sent_at
 */
class Message extends Model
{
    use HasFactory;

    protected $table = 'messages';
    protected $primaryKey = 'message_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'message_id',
        'conversation_id',
        'external_message_id',
        'rfc_message_id',
        'rfc_references',
        'direction',
        'from_email',
        'from_name',
        'body_text',
        'snippet',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'conversation_id');
    }
}
