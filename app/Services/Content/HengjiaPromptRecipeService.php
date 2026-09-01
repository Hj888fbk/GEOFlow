<?php

namespace App\Services\Content;

use App\Models\Admin;
use App\Models\PromptRecipeVersion;
use Illuminate\Support\Facades\DB;

class HengjiaPromptRecipeService
{
    public function active(string $recipeKey): PromptRecipeVersion
    {
        return PromptRecipeVersion::query()
            ->where('recipe_key', $recipeKey)
            ->where('status', PromptRecipeVersion::STATUS_ACTIVE)
            ->latest('activated_at')
            ->latest('id')
            ->firstOrFail();
    }

    /** @param array<string,mixed> $inputContract @param array<string,mixed> $outputContract */
    public function createCandidate(
        string $recipeKey,
        string $version,
        string $template,
        array $inputContract,
        array $outputContract,
        ?Admin $creator = null,
        ?string $changeNotes = null,
    ): PromptRecipeVersion {
        return PromptRecipeVersion::query()->create([
            'recipe_key' => trim($recipeKey),
            'version' => trim($version),
            'status' => PromptRecipeVersion::STATUS_CANDIDATE,
            'template' => trim($template),
            'input_contract' => $inputContract,
            'output_contract' => $outputContract,
            'change_notes' => $changeNotes,
            'created_by_admin_id' => $creator?->getKey(),
        ]);
    }

    public function activate(PromptRecipeVersion $candidate, Admin $reviewer): PromptRecipeVersion
    {
        if ($candidate->status !== PromptRecipeVersion::STATUS_CANDIDATE) {
            throw new \DomainException('Only a candidate prompt recipe can be activated.');
        }

        return DB::transaction(function () use ($candidate, $reviewer): PromptRecipeVersion {
            PromptRecipeVersion::query()
                ->where('recipe_key', $candidate->recipe_key)
                ->where('status', PromptRecipeVersion::STATUS_ACTIVE)
                ->lockForUpdate()
                ->update(['status' => PromptRecipeVersion::STATUS_RETIRED]);

            $locked = PromptRecipeVersion::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PromptRecipeVersion::STATUS_CANDIDATE) {
                throw new \DomainException('Prompt recipe state changed during review.');
            }

            $locked->forceFill([
                'status' => PromptRecipeVersion::STATUS_ACTIVE,
                'reviewed_by_admin_id' => $reviewer->getKey(),
                'activated_by_admin_id' => $reviewer->getKey(),
                'activated_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }
}
