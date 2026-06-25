<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Trip;
use App\Enums\ProjectStatus;

class Project extends Model
{
    use HasFactory;

    protected $table = 'projects';
    protected $primaryKey = 'project_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['project_id','company_id','created_by','project_name','description','start_date','end_date','budget','status'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => ProjectStatus::class,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'project_id', 'project_id');
    }
}
