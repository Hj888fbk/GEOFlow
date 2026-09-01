<?php

namespace Database\Seeders;

use App\Models\PromptRecipeVersion;
use App\Services\Content\HengjiaPromptRecipeCatalog;
use App\Services\Content\PlatformAdapterRegistryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HengjiaContentCenterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(
        HengjiaPromptRecipeCatalog $catalog,
        PlatformAdapterRegistryService $adapters,
    ): void {
        DB::transaction(function () use ($catalog, $adapters): void {
            foreach ($catalog->recipes() as $definition) {
                $hasActiveVersion = PromptRecipeVersion::query()
                    ->where('recipe_key', $definition['recipe_key'])
                    ->where('status', PromptRecipeVersion::STATUS_ACTIVE)
                    ->exists();

                $recipe = PromptRecipeVersion::query()->firstOrCreate(
                    [
                        'recipe_key' => $definition['recipe_key'],
                        'version' => $definition['version'],
                    ],
                    [
                        'status' => $hasActiveVersion
                            ? PromptRecipeVersion::STATUS_CANDIDATE
                            : PromptRecipeVersion::STATUS_ACTIVE,
                        'template' => $definition['template'],
                        'input_contract' => $definition['input_contract'],
                        'output_contract' => $definition['output_contract'],
                        'change_notes' => $definition['change_notes'],
                        'activated_at' => $hasActiveVersion ? null : now(),
                    ],
                );

                if (! $hasActiveVersion && $recipe->status !== PromptRecipeVersion::STATUS_ACTIVE) {
                    $recipe->forceFill([
                        'status' => PromptRecipeVersion::STATUS_ACTIVE,
                        'activated_at' => now(),
                    ])->save();
                }
            }

            $adapters->sync();
        });
    }
}
