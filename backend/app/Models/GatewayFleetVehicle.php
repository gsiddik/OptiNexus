<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'application_id', 'external_vehicle_id', 'registration_number', 'normalized_registration', 'vin', 'status'])]
class GatewayFleetVehicle extends Model
{
    use HasUuidPrimaryKey;

    /** Upper-case, letters and digits only: "B 1234-XYZ" and "b1234xyz" are the same plate. */
    public static function normalizeRegistration(?string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    public function links(): HasMany
    {
        return $this->hasMany(GatewayVehicleLink::class, 'fleet_vehicle_id');
    }
}
