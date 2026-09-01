<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    public const BUILDER_SCHEMA = 'geoflow-prompt-builder/v1';

    public const BUILDER_STATUS_CANDIDATE = 'candidate';

    public const BUILDER_STATUS_ACTIVE = 'active';

    protected $table = 'prompts';

    protected $fillable = [
        'name',
        'type',
        'content',
        'variables',
        'system_key',
        'system_version',
    ];

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'prompt_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'prompt_id');
    }

    public function qualityTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'ai_quality_prompt_id');
    }

    /** @return array<string,mixed> */
    public function builderConfig(): array
    {
        $variables = $this->variables;
        if (! is_string($variables) || trim($variables) === '') {
            return [];
        }

        $decoded = json_decode($variables, true);
        if (! is_array($decoded)
            || ($decoded['schema'] ?? null) !== self::BUILDER_SCHEMA
            || ! is_array($decoded['builder_config'] ?? null)) {
            return [];
        }

        return $decoded['builder_config'];
    }

    public function isGuidedContentPrompt(): bool
    {
        return $this->type === 'content' && $this->builderConfig() !== [];
    }

    public function builderStatus(): ?string
    {
        if (! $this->isGuidedContentPrompt()) {
            return null;
        }

        return (string) ($this->builderConfig()['production_status'] ?? self::BUILDER_STATUS_CANDIDATE);
    }

    public function isAvailableForProductionTask(): bool
    {
        return ! $this->isGuidedContentPrompt() || $this->builderStatus() === self::BUILDER_STATUS_ACTIVE;
    }

    /** @param array<string,mixed> $config */
    public static function encodeBuilderConfig(array $config): string
    {
        return json_encode([
            'schema' => self::BUILDER_SCHEMA,
            'builder_config' => $config,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
