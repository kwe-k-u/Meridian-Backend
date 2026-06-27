<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `subscription_tiers` table.
 *
 * Purpose: Defines the available subscription plans with pricing and feature sets.
 *
 * @property string $tier_id Unique identifier for the tier.
 * @property bool $status Whether the tier is currently active and available.
 */
class SubscriptionTier extends Model
{
    use HasFactory;

    protected $table = 'subscription_tiers';
    protected $primaryKey = 'tier_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tier_id',
        'name',
        'price_quarterly',
        'features',
        'status',
    ];

    protected $casts = [
        'features' => 'array',
        'status' => 'boolean',
    ];

    public function companySubscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class, 'tier_id', 'tier_id');
    }
}