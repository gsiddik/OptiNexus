<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['seq', 'reading_id', 'tenant_id', 'source', 'device_ref', 'odometer_km', 'odometer_kind', 'recorded_at'])]
class GatewayOdometerReading extends Model
{
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
