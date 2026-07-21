<?php

declare(strict_types=1);

namespace SolutionForest\AiKit;

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
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }

    public function tenancyEnabled(): bool
    {
        return (bool) config('ai-kit.tenancy.enabled', false);
    }
}
