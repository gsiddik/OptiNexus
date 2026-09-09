<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['approval_definition_id', 'level_order', 'name', 'approver_type', 'approver_reference', 'condition'])]
class ApprovalLevel extends Model
{
    use HasUuidPrimaryKey;

    public const APPROVER_USER = 'USER';

    public const APPROVER_ROLE = 'ROLE';

    public const APPROVER_TENANT_ROLE = 'TENANT_ROLE';

    public const APPROVER_APPLICATION_ROLE = 'APPLICATION_ROLE';

    public const APPROVER_DYNAMIC = 'DYNAMIC';

    public const APPROVER_TYPES = [
        self::APPROVER_USER, self::APPROVER_ROLE, self::APPROVER_TENANT_ROLE,
        self::APPROVER_APPLICATION_ROLE, self::APPROVER_DYNAMIC,
    ];

    protected function casts(): array
    {
        return ['level_order' => 'integer', 'condition' => 'array'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ApprovalDefinition::class, 'approval_definition_id');
    }
}
