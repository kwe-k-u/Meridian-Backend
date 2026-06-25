<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\CallActionItem;

class Call extends Model
{
    use HasFactory;

    protected $table = 'calls';
    protected $primaryKey = 'call_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['call_id','project_id','organized_by','title','started_at','ended_at','meeting_link','notes','transcript'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(CallActionItem::class, 'call_id', 'call_id');
    }
}
