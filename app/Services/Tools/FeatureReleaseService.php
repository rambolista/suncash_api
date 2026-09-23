<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\FeatureReleaseControl;
use App\Models\Mysuncash\FeatureReleaseIsland;
use App\Models\Mysuncash\Island;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Feature Release Management" (legacy `tools/feature_release` +
 * `tools_model::add_feature()`/`edit_feature()`/`get_features()`). Gates a
 * feature by a release date, either for everyone (`scope: all`) or a
 * specific set of islands (`scope: specific`, backed by `feature_release_islands`).
 *
 * Legacy's list page resolves each `specific`-scope row's island names with
 * a separate query per row (fine at legacy's 1-row scale, but the shape to
 * avoid) — batched here into a single `whereIn` instead, same pattern as
 * every other N+1 fix this session.
 *
 * Legacy also defines full Activate/Inactivate model methods and AJAX
 * endpoints, but never renders a button for either anywhere in its own
 * view — genuinely dead, unreachable functionality in the admin it's meant
 * to be a faithful clone of. Not ported: scope here is Add/List/Edit only,
 * matching what the legacy UI actually exposes.
 *
 * Legacy's edit-save path resubmits the `datetime-local` field as-is
 * without add's `T`-to-space conversion, a real formatting bug (only
 * exercised on edit, not add). Not replicated — both paths parse the date
 * the same way here, so there's nothing to diverge.
 */
class FeatureReleaseService
{
    public function islands(): array
    {
        return Island::where('status', 'A')->orderBy('name')->get(['id', 'name'])->all();
    }

    public function list(): array
    {
        $releases = FeatureReleaseControl::orderByDesc('id')->get();

        $islandNamesByRelease = FeatureReleaseIsland::whereIn('release_id', $releases->where('scope', FeatureReleaseControl::SCOPE_SPECIFIC)->pluck('id'))
            ->with('island:id,name')
            ->get()
            ->groupBy('release_id');

        return $releases->map(fn (FeatureReleaseControl $release) => $this->present($release, $islandNamesByRelease->get($release->id, collect())))->all();
    }

    private function present(FeatureReleaseControl $release, $islandRows): array
    {
        return [
            'id' => $release->id,
            'feature_type' => $release->feature_type,
            'scope' => $release->scope,
            'release_date' => $release->release_date,
            'status' => $release->status,
            'island_ids' => $islandRows->pluck('island_id')->all(),
            'island_names' => $islandRows->pluck('island.name')->filter()->implode(', '),
            'created_at' => $release->created_at,
            'updated_at' => $release->updated_at,
        ];
    }

    private function validate(array $data, ?int $ignoreId = null): array
    {
        $errors = [];

        $duplicate = FeatureReleaseControl::where('feature_type', $data['feature_type'] ?? null)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($duplicate) {
            $errors['feature_type'] = ['This feature type already has a release configured.'];
        }

        if (($data['scope'] ?? null) === FeatureReleaseControl::SCOPE_SPECIFIC && empty($data['islands'])) {
            $errors['islands'] = ['Please select at least one island.'];
        }

        return $errors;
    }

    /** @throws ValidationException */
    public function create(array $data, User $actor): array
    {
        $errors = $this->validate($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $release = DB::connection('mysuncash')->transaction(function () use ($data, $actor) {
            $release = FeatureReleaseControl::create([
                'feature_type' => trim($data['feature_type']),
                'scope' => $data['scope'],
                'release_date' => $data['release_date'],
                'status' => FeatureReleaseControl::STATUS_ACTIVE,
                'created_by' => $actor->id,
            ]);

            $this->syncIslands($release, $data);

            return $release;
        });

        ActivityLog::recordCreated($actor, 'Tools - Feature Release Management', $release, ['feature_type', 'scope', 'release_date']);

        return $this->present($release, $release->islands()->with('island:id,name')->get());
    }

    /** @throws ValidationException */
    public function update(int $id, array $data, User $actor): array
    {
        $release = FeatureReleaseControl::find($id);
        if (! $release) {
            throw ValidationException::withMessages(['id' => ['This feature release was not found.']]);
        }

        $errors = $this->validate($data, $id);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $before = $release->getAttributes();

        DB::connection('mysuncash')->transaction(function () use ($release, $data, $actor) {
            $release->update([
                'feature_type' => trim($data['feature_type']),
                'scope' => $data['scope'],
                'release_date' => $data['release_date'],
                'updated_by' => $actor->id,
            ]);

            $this->syncIslands($release, $data);
        });

        ActivityLog::recordUpdated($actor, 'Tools - Feature Release Management', $release, $before, ['feature_type', 'scope', 'release_date']);

        return $this->present($release, $release->islands()->with('island:id,name')->get());
    }

    /** Legacy's own "replace all" pattern — simplest correct approach at this scale (a handful of islands per release). */
    private function syncIslands(FeatureReleaseControl $release, array $data): void
    {
        FeatureReleaseIsland::where('release_id', $release->id)->delete();

        if ($release->scope === FeatureReleaseControl::SCOPE_SPECIFIC) {
            FeatureReleaseIsland::insert(array_map(fn ($islandId) => [
                'release_id' => $release->id,
                'island_id' => $islandId,
            ], $data['islands']));
        }
    }
}
