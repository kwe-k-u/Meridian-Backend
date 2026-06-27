<?php

namespace App\Models;

use App\Enums\CallActionItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `call_action_items` table.
 *
 * Purpose: Tracks individual action items or to-dos that result from a call.
 *
 * @property string $action_item_id Unique identifier for the action item.
 * @property string $call_id Foreign key to the associated call.
 * @property CallActionItemStatus $status Current status of the action item (e.g., pending, completed).
 */
class CallActionItem extends Model
{
    use HasFactory;

    protected $table = 'call_action_items';
    protected $primaryKey = 'action_item_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'action_item_id',
        'call_id',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => CallActionItemStatus::class,
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_id', 'call_id');
    }
}
