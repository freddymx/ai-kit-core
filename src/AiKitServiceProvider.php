<?php

declare(strict_types=1);

namespace SolutionForest\AiKit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use SolutionForest\AiKit\Chunking\RecursiveTextChunker;
use SolutionForest\AiKit\Console\DemoCommand;
use SolutionForest\AiKit\Console\IngestCommand;
use SolutionForest\AiKit\Console\IngestUrlCommand;
use SolutionForest\AiKit\Console\InstallCommand;
use SolutionForest\AiKit\Contracts\Chunker;
use SolutionForest\AiKit\Contracts\Embedder;
use SolutionForest\AiKit\Contracts\Retriever;
use SolutionForest\AiKit\Contracts\TextExtractor;
use SolutionForest\AiKit\Contracts\VectorIndex;
use SolutionForest\AiKit\Embeddings\AiSdkEmbedder;
use SolutionForest\AiKit\Extraction\ExcelExtractor;
use SolutionForest\AiKit\Extraction\ExtractorManager;
use SolutionForest\AiKit\Extraction\HtmlExtractor;
use SolutionForest\AiKit\Extraction\PdfExtractor;
use SolutionForest\AiKit\Extraction\PlainTextExtractor;
use SolutionForest\AiKit\Extraction\WordExtractor;
use SolutionForest\AiKit\Retrieval\PgVectorRetriever;
use SolutionForest\AiKit\Retrieval\SqliteVecRetriever;

final class AiKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-kit.php', 'ai-kit');

        $this->app->singleton(AiKit::class);

        $this->app->singleton(Embedder::class, fn (): Embedder => AiSdkEmbedder::fromConfig());

        $this->app->singleton(Chunker::class, RecursiveTextChunker::class);

        $this->app->singleton(TextExtractor::class, fn (): TextExtractor => new ExtractorManager(
            new PlainTextExtractor(),
            new HtmlExtractor(),
            new PdfExtractor(),
            new WordExtractor(),
            new ExcelExtractor(),
        ));

        // The vector driver is one object that is both writable index and reader.
        $this->app->singleton(VectorIndex::class, fn (Application $app): VectorIndex => $this->makeVectorIndex($app));
        $this->app->singleton(Retriever::class, fn (Application $app): Retriever => $app->make(VectorIndex::class));
    }

    private function makeVectorIndex(Application $app): VectorIndex
    {
        $embedder = $app->make(Embedder::class);
        $connection = DB::connection(config('ai-kit.database.connection'));

        return match (config('ai-kit.retrieval.driver')) {
            'pgvector' => new PgVectorRetriever($embedder, $connection),
            default => new SqliteVecRetriever($embedder, $connection),
        };
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
                IngestCommand::class,
                IngestUrlCommand::class,
                DemoCommand::class,
            ]);
        }

        // Load migrations for tests / auto-discovery; publishing lets apps own them.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/ai-kit.php' => config_path('ai-kit.php'),
        ], 'ai-kit-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'ai-kit-migrations');

        $this->publishes([
            __DIR__ . '/../resources/stubs/SupportAgent.php.stub' => app_path('Ai/SupportAgent.php'),
        ], 'ai-kit-agent');
    }
}
