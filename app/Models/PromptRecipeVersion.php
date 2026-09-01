<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptRecipeVersion extends Model
{
    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_CANDIDATE, self::STATUS_ACTIVE, self::STATUS_RETIRED];

    protected $attributes = ['status' => self::STATUS_CANDIDATE];

    protected $fillable = [
        'recipe_key',
        'version',
        'status',
        'template',
        'input_contract',
        'output_contract',
        'change_notes',
        'created_by_admin_id',
        'reviewed_by_admin_id',
        'activated_by_admin_id',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'input_contract' => 'array',
            'output_contract' => 'array',
            'created_by_admin_id' => 'integer',
            'reviewed_by_admin_id' => 'integer',
            'activated_by_admin_id' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'activated_by_admin_id');
    }

    public function contentMasters(): HasMany
    {
        return $this->hasMany(ContentMaster::class);
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(ContentGenerationRun::class);
    }
}
