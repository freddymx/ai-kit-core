<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Pipeline;

use SolutionForest\AiKit\Contracts\Retriever;
use SolutionForest\AiKit\Data\ChunkCollection;
use SolutionForest\AiKit\Data\RetrievalOptions;

/**
 * Classic-RAG helper: retrieves chunks for a user message and renders a context
 * block to prepend to the prompt (inject mode). Also exposes the raw chunks so
 * the UI can render citations even when the agent has no search tool.
 */
final class ContextInjector
{
    public function __construct(private readonly Retriever $retriever)
    {
    }

    /**
     * Retrieve chunks relevant to $message within the given collection/tenant.
     */
    public function retrieve(string $message, ?string $collection = null, ?string $tenantId = null): ChunkCollection
    {
        $opts = RetrievalOptions::fromConfig($collection)->withTenant($tenantId);

        return $this->retriever->search($message, $opts);
    }

    /**
     * Build the augmented prompt: retrieved context followed by the question.
     */
    public function inject(string $message, ?string $collection = null, ?string $tenantId = null): string
    {
        $chunks = $this->retrieve($message, $collection, $tenantId);

        if ($chunks->isEmpty()) {
            return $message;
        }

        return <<<PROMPT
        Use the following retrieved context to answer the question. If the context
        does not contain the answer, say so. Cite sources by document title.

        <context>
        {$chunks->toContextString()}
        </context>

        Question: {$message}
        PROMPT;
    }
}
