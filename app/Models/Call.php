<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
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
