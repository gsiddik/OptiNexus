<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'application_id', 'parent_id', 'type', 'code', 'name', 'description',
    'sort_order', 'status', 'metadata',
])]
class Capability extends Model
{
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    public const TYPE_MODULE = 'MODULE';

    public const TYPE_MENU = 'MENU';

    public const TYPE_SUBMENU = 'SUBMENU';

    public const TYPE_FEATURE = 'FEATURE';

    public const TYPE_FUNCTION = 'FUNCTION';

    public const TYPE_ACTION = 'ACTION';

    public const TYPES = [
        self::TYPE_MODULE, self::TYPE_MENU, self::TYPE_SUBMENU,
        self::TYPE_FEATURE, self::TYPE_FUNCTION, self::TYPE_ACTION,
    ];

    /**
     * Allowed parent type for each capability type. A MODULE has no parent
     * (its parent_id must be null / it is application-level).
     */
    public const ALLOWED_PARENT_TYPES = [
        self::TYPE_MODULE => null,
        self::TYPE_MENU => [self::TYPE_MODULE],
        self::TYPE_SUBMENU => [self::TYPE_MENU, self::TYPE_SUBMENU],
        self::TYPE_FEATURE => [self::TYPE_MODULE, self::TYPE_MENU, self::TYPE_SUBMENU],
        self::TYPE_FUNCTION => [self::TYPE_FEATURE],
        self::TYPE_ACTION => [self::TYPE_FUNCTION, self::TYPE_FEATURE],
    ];

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DISABLED = 'DISABLED';

    public const STATUS_DEPRECATED = 'DEPRECATED';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_DEPRECATED];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Capability::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Capability::class, 'parent_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    /**
     * All descendant ids (including self), used for circular-hierarchy checks.
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $queue = [$this->id];

        while ($queue) {
            $childIds = Capability::query()->whereIn('parent_id', $queue)->pluck('id')->all();
            $queue = array_diff($childIds, $ids);
            $ids = array_merge($ids, $queue);
        }

        return $ids;
    }
}
