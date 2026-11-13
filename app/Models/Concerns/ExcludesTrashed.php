<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ExcludesTrashed
{
    protected static function bootExcludesTrashed(): void
    {
        static::addGlobalScope('trashed', function (Builder $builder): void {
            $model = $builder->getModel();

            $builder->whereNotIn($model->getTable().'.id', function ($query) use ($model): void {
                $query->select('resource_id')
                    ->from('trash_entries')
                    ->where('resource_type', $model::trashType());
            });
        });
    }

    public static function trashType(): string
    {
        return static::$trashType;
    }
}
