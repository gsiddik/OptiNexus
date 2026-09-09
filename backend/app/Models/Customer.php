<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'customer_code', 'legal_name', 'business_name', 'tax_id', 'industry',
    'email', 'phone', 'billing_address', 'status', 'metadata',
])]
class Customer extends Model
{
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_TERMINATED = 'TERMINATED';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_TERMINATED, self::STATUS_ARCHIVED,
    ];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'metadata' => 'array',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
