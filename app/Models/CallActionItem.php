<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\CallActionStatus;

class CallActionItem extends Model
{
    use HasFactory;

    protected $table = 'call_action_items';
    protected $primaryKey = 'action_item_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['action_item_id','call_id','description','status'];

    protected $casts = [
        'status' => CallActionStatus::class,
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_id', 'call_id');
    }
}
