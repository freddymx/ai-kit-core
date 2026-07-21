<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Embeddings;

use Laravel\Ai\Embeddings;
use RuntimeException;
use SolutionForest\AiKit\Contracts\Embedder;
use Throwable;

/**
 * Embedder backed by the official Laravel AI SDK Embeddings API.
 *
 * Provider/model/dimensions come from config('ai-kit.embeddings.*'). Tests
 * bind a fake Embedder instead, and can also use Embeddings::fake().
 */
final class AiSdkEmbedder implements Embedder
{
    public function __construct(
        private readonly string $provider = 'openai',
        private readonly string $model = 'text-embedding-3-small',
        private readonly int $dimensions = 1536,
        private readonly bool $cache = true,
        private readonly int $timeout = 120,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            provider: (string) config('ai-kit.embeddings.provider', 'openai'),
            model: (string) config('ai-kit.embeddings.model', 'text-embedding-3-small'),
            dimensions: (int) config('ai-kit.embeddings.dimensions', 1536),
            cache: (bool) config('ai-kit.embeddings.cache', true),
            timeout: (int) config('ai-kit.embeddings.timeout', 120),
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param  list<string>  $texts
     * @return list<array<int, float>>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $builder = Embeddings::for(array_values($texts))
            ->dimensions($this->dimensions)
            ->timeout($this->timeout);

        if ($this->cache) {
            $builder = $builder->cache();
        }

        $response = $this->generate($builder);

        return $this->normalise($response, count($texts));
    }

    /**
     * @return array<int, float>
     */
    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0] ?? [];
    }

    private function generate(object $builder): mixed
    {
        $lab = $this->resolveLab($this->provider);

        try {
            return $lab !== null
                ? $builder->generate($lab, $this->model)
                : $builder->generate();
        } catch (Throwable $e) {
            throw new RuntimeException("Embedding generation failed: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Resolve a provider string to the SDK's Lab enum without hard-failing if
     * the enum's shape differs; null means "use the SDK default provider".
     */
    private function resolveLab(string $provider): ?object
    {
        $enum = 'Laravel\\Ai\\Enums\\Lab';

        if (! enum_exists($enum)) {
            return null;
        }

        foreach ($enum::cases() as $case) {
            if (strcasecmp($case->name, $provider) === 0 || strcasecmp($case->value ?? '', $provider) === 0) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Coerce the SDK response into a list of float vectors. The response is an
     * iterable of embeddings; each item may be a raw array or expose the vector
     * via ->embedding / ->vector / ->values / toArray().
     *
     * @return list<array<int, float>>
     */
    private function normalise(mixed $response, int $expected): array
    {
        $items = $this->toIterable($response);

        $vectors = [];
        foreach ($items as $item) {
            $vectors[] = $this->toVector($item);
        }

        if (count($vectors) !== $expected) {
            throw new RuntimeException(
                "Expected {$expected} embeddings from the AI SDK, received " . count($vectors) . '.'
            );
        }

        return $vectors;
    }

    /**
     * @return iterable<mixed>
     */
    private function toIterable(mixed $response): iterable
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response)) {
            if (property_exists($response, 'embeddings')) {
                return $response->embeddings;
            }
            if (method_exists($response, 'all')) {
                return $response->all();
            }
            if (method_exists($response, 'toArray')) {
                return $response->toArray();
            }
            if ($response instanceof \Traversable) {
                return $response;
            }
        }

        throw new RuntimeException('Unrecognised embeddings response from the AI SDK.');
    }

    /**
     * @return array<int, float>
     */
    private function toVector(mixed $item): array
    {
        if (is_array($item)) {
            // Either a raw vector or ['embedding' => [...]].
            if (isset($item['embedding']) && is_array($item['embedding'])) {
                return array_map('floatval', $item['embedding']);
            }

            return array_map('floatval', $item);
        }

        if (is_object($item)) {
            foreach (['embedding', 'vector', 'values'] as $prop) {
                if (isset($item->{$prop}) && is_array($item->{$prop})) {
                    return array_map('floatval', $item->{$prop});
                }
            }
            if (method_exists($item, 'toArray')) {
                return array_map('floatval', $item->toArray());
            }
        }

        throw new RuntimeException('Unable to read a vector from the embeddings response item.');
    }
}
