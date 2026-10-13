<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait VisibleTo
{
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->whereNull($query->getModel()->getTable().'.created_by');
            if ($user) {
                $query->orWhere($query->getModel()->getTable().'.created_by', $user->id);
            }
        });
    }
}
