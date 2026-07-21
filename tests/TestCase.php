<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SolutionForest\AiKit\AiKitServiceProvider;
use SolutionForest\AiKit\Contracts\Embedder;
use SolutionForest\AiKit\Tests\Support\FakeEmbedder;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Ai\AiServiceProvider::class,
            AiKitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('ai-kit.retrieval.driver', 'sqlite');
        // The offline FakeEmbedder is lexical, so cosine scores run lower than
        // real embeddings — relax the floor so realistic queries still retrieve.
        $app['config']->set('ai-kit.retrieval.min_score', 0.05);
        $app['config']->set('ai-kit.embeddings.dimensions', FakeEmbedder::DIMENSIONS);
        $app['config']->set('ai-kit.embeddings.cache', false);
        $app['config']->set('ai-kit.chunking.size', 300);
        $app['config']->set('ai-kit.chunking.overlap', 40);

        // Enable the HTTP API for tests, without an auth guard (no sanctum in CI).
        $app['config']->set('ai-kit.features.api', true);
        $app['config']->set('ai-kit.api.middleware', ['api']);

        // Deterministic, offline embeddings — no API keys required in CI.
        $app->singleton(Embedder::class, fn (): Embedder => new FakeEmbedder());
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../vendor/laravel/ai/database/migrations');
    }
}
