<?php

namespace TypiCMS\Modules\Core\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use TypiCMS\Modules\Files\Models\File;
use TypiCMS\Modules\Tags\Models\Tag;

abstract class Base extends Model
{
    use Cachable;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        /*
         * This provide a way to add fields to the model’s translatable attributes.
         */
        if (config('typicms.'.$this->getTable().'.translatable') && property_exists($this, 'translatable')) {
            $this->translatable = config('typicms.'.$this->getTable().'.translatable');
        }
    }

    public function previewUri(): string
    {
        $uri = '/';
        if ($this->id) {
            $uri = $this->uri();
        }

        return url($uri);
    }

    public function uri($locale = null): string
    {
        $locale = $locale ?: config('app.locale');
        $route = $locale.'::'.Str::singular($this->getTable());
        if (Route::has($route)) {
            return route($route, $this->slug);
        }

        return '/';
    }

    public function scopePublished(Builder $query): Builder
    {
        if (request('preview')) {
            return $query;
        }
        $field = 'status';
        if (in_array($field, (array) $this->translatable)) {
            $field .= '->'.config('app.locale');
        }

        return $query->where($field, '1');
    }

    public function scopeWhereSlugIs($query, $slug): Builder
    {
        $field = 'slug';
        if (in_array($field, (array) $this->translatable)) {
            $field .= '->'.config('app.locale');
        }

        return $query->where($field, $slug);
    }

    public function scopeOrder(Builder $query): Builder
    {
        if ($order = config('typicms.'.$this->getTable().'.order')) {
            foreach ($order as $field => $direction) {
                $query->orderBy($field, $direction);
            }
        }

        return $query;
    }

    public function scopeSelectFields($query, string $fields): Builder
    {
        $locale = request('locale', config('app.locale'));
        $fields = explode(',', $fields);
        foreach ($fields as $field) {
            if (isset($this->translatable) && $this->isTranslatableAttribute($field)) {
                if ($field === 'status') {
                    $query
                        ->selectRaw('
                            CAST(JSON_UNQUOTE(
                                JSON_EXTRACT(`'.$field.'`, \'$.'.$locale.'\')
                            ) AS UNSIGNED) AS `'.$field.'_translated`
                        ');
                } else {
                    $query
                        ->selectRaw('
                            CASE WHEN
                            JSON_UNQUOTE(
                                JSON_EXTRACT(`'.$field.'`, \'$.'.$locale.'\')
                            ) = \'null\' THEN NULL
                            ELSE
                            JSON_UNQUOTE(
                                JSON_EXTRACT(`'.$field.'`, \'$.'.$locale.'\')
                            )
                            END '.
                            (config('typicms.mariadb') === false ? 'COLLATE '.(DB::connection()->getConfig()['collation'] ?? 'utf8mb4_unicode_ci') : '').'
                            AS `'.$field.'_translated`
                        ');
                }
            } else {
                $query->addSelect($field);
            }
        }

        return $query;
    }

    public function setStatusAttribute($status)
    {
        if (is_array($status)) {
            $this->attributes['status'] = json_encode(array_map(function ($item) {
                return (int) $item;
            }, $status));
        }
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')
            ->orderBy('tag')
            ->withTimestamps();
    }

    public function editUrl(): string
    {
        $route = 'admin::edit-'.Str::singular($this->getTable());
        if (Route::has($route)) {
            return route($route, $this->id);
        }

        return route('dashboard');
    }

    public function indexUrl(): string
    {
        $route = 'admin::index-'.$this->getTable();
        if (Route::has($route)) {
            return route($route);
        }

        return route('dashboard');
    }

    public function next(Model $model, int $category_id = null): ?Model
    {
        return $this->adjacent(1, $model, $category_id);
    }

    public function prev(Model $model, int $category_id = null): ?Model
    {
        return $this->adjacent(-1, $model, $category_id);
    }

    public function adjacent(int $direction, Model $model, int $category_id = null): ?Model
    {
        $currentModel = $model;
        if ($category_id !== null) {
            $models = $this->published()
                ->with('category')
                ->where('category_id', $category_id)
                ->get(['id', 'category_id', 'slug']);
        } else {
            $models = $this->published()->get(['id', 'slug']);
        }
        foreach ($models as $key => $model) {
            if ($currentModel->id === $model->id) {
                $adjacentKey = $key + $direction;

                return isset($models[$adjacentKey]) ? $models[$adjacentKey] : null;
            }
        }
    }

    public function detachFile(File $file): void
    {
        $filesIds = $this->files->pluck('id')->toArray();
        $pivotData = [];
        $position = 1;
        foreach ($filesIds as $fileId) {
            if ($fileId != $file->id) {
                $pivotData[$fileId] = ['position' => $position++];
            }
        }
        $this->files()->sync($pivotData);
    }

    public function attachFiles(Request $request): JsonResponse
    {
        // Get the collection of files to add.
        $fileIds = $request->only('files')['files'];
        $files = File::whereIn('id', $fileIds)->get();

        // Empty collection that will contain files that are in selected directories.
        $subFiles = collect();

        // Remove folders and build array of ids.
        $newFiles = $files->each(function (Model $item) use ($subFiles) {
            if ($item->type === 'f') {
                foreach ($item->children as $file) {
                    if ($file->type !== 'f') {
                        // Add files in this directory to collection of SubFiles
                        $subFiles->push($file);
                    }
                }
            }
        })->reject(function (Model $item) {
            return $item->type === 'f';
        })
            ->pluck('id')
            ->toArray();

        // Transform subfiles collection to array of ids.
        $subFiles = $subFiles->pluck('id')->toArray();

        // Merge files with files in directory.
        $newFiles = array_merge($newFiles, $subFiles);

        // Get files that are already in the gallery.
        $filesIds = $this->files->pluck('id')->toArray();

        // Merge with new files.
        $files = array_unique(array_merge($filesIds, $newFiles));
        $number = count($files) - count($filesIds);

        // Prepare synchronisation.
        $pivotData = [];
        $position = 1;
        foreach ($files as $fileId) {
            $pivotData[$fileId] = ['position' => $position++];
        }

        // Sync.
        $this->files()->sync($pivotData);

        return response()->json([
            'number' => $number,
            'message' => __(':number items were added.', compact('number')),
            'models' => $this->files()->get(),
        ]);
    }
}
