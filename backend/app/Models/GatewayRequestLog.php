<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'application_id', 'service_account_id', 'method', 'path', 'status_code', 'duration_ms', 'correlation_id'])]
class GatewayRequestLog extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;
}
