<?php

declare(strict_types=1);

namespace SolutionForest\AiKit;

use SolutionForest\AiKit\Support\AiProviderResolver;
use Throwable;

/**
 * Lightweight runtime context for ai-kit — currently just the active tenant.
 *
 * Registered as a singleton; use the AiKitManager facade or the container.
 *
 *   AiKit::withTenant('acme', fn () => $retriever->search(...));
 *   AiKit::setTenant('acme');
 */
final class AiKit
{
    private ?string $tenantId = null;

    public function tenant(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function setTenant(?string $tenantId): self
    {
        $this->tenantId = $tenantId;
        $this->reapplyProviderConfig();

        return $this;
    }

    /**
     * Run $callback with $tenantId active, restoring the previous tenant after.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function withTenant(?string $tenantId, callable $callback): mixed
    {
        $previous = $this->tenantId;
        $this->setTenant($tenantId);

        try {
            return $callback();
        } finally {
            // Always restore the previous tenant, including on exceptions, and
            // re-publish its config as well.
            $this->setTenant($previous);
        }
    }

    /**
     * config() is process-global: the settings of the newly active tenant must
     * be published immediately so direct SDK/config readers see them. The
     * resolver never calls setTenant(), so there is no recursion here.
     */
    private function reapplyProviderConfig(): void
    {
        try {
            if (app()->bound(AiProviderResolver::class)) {
                app(AiProviderResolver::class)->applyToConfig();
            }
        } catch (Throwable) {
            // A missing settings table or an unavailable DB must never break a
            // tenant switch (or app boot).
        }
    }

    public function tenancyEnabled(): bool
    {
        return (bool) config('ai-kit.tenancy.enabled', false);
    }
}
