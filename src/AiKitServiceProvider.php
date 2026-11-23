<?php

declare(strict_types=1);

namespace SolutionForest\AiKit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Storage\DatabaseConversationStore;
use SolutionForest\AiKit\Mcp\AiKitServer;
use SolutionForest\AiKit\Chunking\RecursiveTextChunker;
use SolutionForest\AiKit\Console\ChatCommand;
use SolutionForest\AiKit\Console\CollectionCommand;
use SolutionForest\AiKit\Console\ConversationCommand;
use SolutionForest\AiKit\Console\DemoCommand;
use SolutionForest\AiKit\Console\DocumentCommand;
use SolutionForest\AiKit\Console\IngestCommand;
use SolutionForest\AiKit\Console\IngestUrlCommand;
use SolutionForest\AiKit\Console\InstallCommand;
use SolutionForest\AiKit\Console\SearchCommand;
use SolutionForest\AiKit\Console\StatsCommand;
use SolutionForest\AiKit\Contracts\Chunker;
use SolutionForest\AiKit\Contracts\Embedder;
use SolutionForest\AiKit\Contracts\Retriever;
use SolutionForest\AiKit\Contracts\TextExtractor;
use SolutionForest\AiKit\Contracts\VectorIndex;
use SolutionForest\AiKit\Conversations\ConsistentConversationStore;
use SolutionForest\AiKit\Embeddings\AiSdkEmbedder;
use SolutionForest\AiKit\Extraction\ExcelExtractor;
use SolutionForest\AiKit\Extraction\ExtractorManager;
use SolutionForest\AiKit\Extraction\HtmlExtractor;
use SolutionForest\AiKit\Extraction\PdfExtractor;
use SolutionForest\AiKit\Extraction\PlainTextExtractor;
use SolutionForest\AiKit\Extraction\WordExtractor;
use SolutionForest\AiKit\Retrieval\PgVectorRetriever;
use SolutionForest\AiKit\Retrieval\SqliteVecRetriever;
use SolutionForest\AiKit\Support\AiProviderResolver;

final class AiKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-kit.php', 'ai-kit');

        $this->app->singleton(AiKit::class);

        $this->app->singleton(AiProviderResolver::class);

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
                // Headless parity with the Filament panel:
                CollectionCommand::class,
                DocumentCommand::class,
                SearchCommand::class,
                ChatCommand::class,
                ConversationCommand::class,
                StatsCommand::class,
            ]);
        }

        // Load migrations for tests / auto-discovery; publishing lets apps own them.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->registerConversationStore();
        $this->registerApiRoutes();
        $this->registerMcpServer();
    }

    /**
     * Swap the AI SDK's conversation store for one that keeps tool calls paired
     * with their outputs, so a truncated tool loop cannot leave a conversation
     * permanently unusable (a 400 "No tool output found for function call …").
     *
     * Bound in boot() — every register() has run by then, so this reliably wins
     * over the SDK's own binding regardless of provider order. An application
     * that supplies its own store is left alone.
     */
    private function registerConversationStore(): void
    {
        if (! class_exists(DatabaseConversationStore::class)) {
            return;
        }

        $current = $this->app->bound(ConversationStore::class)
            ? $this->app->make(ConversationStore::class)
            : null;

        if ($current !== null && $current::class !== DatabaseConversationStore::class) {
            return;
        }

        $this->app->singleton(ConversationStore::class, fn (): ConversationStore => new ConsistentConversationStore(
            config('ai.conversations.connection'),
        ));
    }

    /**
     * Register the optional JSON API (config-gated, prefixed, guarded by the
     * configured middleware).
     */
    private function registerApiRoutes(): void
    {
        if (! config('ai-kit.features.api', false)) {
            return;
        }

        Route::middleware((array) config('ai-kit.api.middleware', ['api']))
            ->prefix((string) config('ai-kit.api.prefix', 'api/ai-kit'))
            ->group(__DIR__ . '/../routes/api.php');
    }

    /**
     * Register the optional MCP server. Requires laravel/mcp — silently skipped
     * when the package is not installed, so core has no hard dependency on it.
     */
    private function registerMcpServer(): void
    {
        if (! config('ai-kit.features.mcp', false) || ! class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            return;
        }

        $handle = (string) config('ai-kit.mcp.handle', 'ai-kit');
        \Laravel\Mcp\Facades\Mcp::local($handle, AiKitServer::class);

        if (config('ai-kit.mcp.web', false)) {
            \Laravel\Mcp\Facades\Mcp::web((string) config('ai-kit.mcp.web_route', 'mcp/ai-kit'), AiKitServer::class)
                ->middleware((array) config('ai-kit.mcp.web_middleware', ['api']));
        }
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
