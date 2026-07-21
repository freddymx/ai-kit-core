<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\AiKit\AiKit;

/**
 * Scopes a model to the active ai-kit tenant when one is set, and stamps the
 * tenant_id on new records. No-op when tenancy is disabled or no tenant is set.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('aiKitTenant', function (Builder $builder): void {
            $tenant = app(AiKit::class);

            if ($tenant->tenancyEnabled() && $tenant->hasTenant()) {
                $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $tenant->tenant());
            }
        });

        static::creating(function (Model $model): void {
            $tenant = app(AiKit::class);

            if ($model->getAttribute('tenant_id') === null && $tenant->hasTenant()) {
                $model->setAttribute('tenant_id', $tenant->tenant());
            }
        });
    }
}
