<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'fleet_vehicle_id', 'telematics_source', 'device_ref', 'device_name', 'device_registration', 'link_type', 'status', 'created_by'])]
class GatewayVehicleLink extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_AUTO = 'AUTO';

    public const TYPE_MANUAL = 'MANUAL';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_UNMATCHED = 'UNMATCHED';

    public const STATUS_INACTIVE = 'INACTIVE';

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(GatewayFleetVehicle::class, 'fleet_vehicle_id');
    }
}
