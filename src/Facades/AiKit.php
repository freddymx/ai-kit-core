<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Facades;

use Illuminate\Support\Facades\Facade;
use SolutionForest\AiKit\AiKit as AiKitManager;

/**
 * @method static string|null tenant()
 * @method static bool hasTenant()
 * @method static AiKitManager setTenant(?string $tenantId)
 * @method static mixed withTenant(?string $tenantId, callable $callback)
 * @method static bool tenancyEnabled()
 *
 * @see \SolutionForest\AiKit\AiKit
 */
final class AiKit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AiKitManager::class;
    }
}
