<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Providers\Provider;
use RuntimeException;
use SolutionForest\AiKit\Agents\RagAgent;
use SolutionForest\AiKit\AiKitServiceProvider;
use SolutionForest\AiKit\Embeddings\AiSdkEmbedder;
use SolutionForest\AiKit\Support\AiProviderResolver;
use SolutionForest\AiKit\Support\AiSettings;

uses(RefreshDatabase::class);

function resolver(): AiProviderResolver
{
    return app(AiProviderResolver::class);
}

it('falls back to config when there is no database row', function (): void {
    config()->set('ai-kit.chat.provider', 'anthropic');
    config()->set('ai-kit.chat.model', 'claude-sonnet-5');
    config()->set('ai-kit.embeddings.provider', 'openai');
    config()->set('ai-kit.embeddings.model', 'text-embedding-3-small');
    config()->set('ai-kit.embeddings.dimensions', 1536);

    expect(resolver()->chatProvider())->toBe('anthropic')
        ->and(resolver()->chatModel())->toBe('claude-sonnet-5')
        ->and(resolver()->embeddingsProvider())->toBe('openai')
        ->and(resolver()->embeddingsModel())->toBe('text-embedding-3-small')
        ->and(resolver()->embeddingsDimensions())->toBe(1536)
        ->and(resolver()->effective()['sources']['chat_provider'])->toBe('env');
});

it('prefers database overrides over config', function (): void {
    config()->set('ai-kit.chat.provider', 'anthropic');
    config()->set('ai-kit.chat.model', 'claude-sonnet-5');

    resolver()->save([
        'chat_provider' => 'openrouter',
        'chat_model' => 'openai/gpt-5',
        'embeddings_dimensions' => '512',
    ]);

    expect(resolver()->chatProvider())->toBe('openrouter')
        ->and(resolver()->chatModel())->toBe('openai/gpt-5')
        ->and(resolver()->embeddingsDimensions())->toBe(512)
        ->and(resolver()->effective()['sources']['chat_provider'])->toBe('database')
        ->and(resolver()->effective()['sources']['chat_model'])->toBe('database')
        ->and(resolver()->effective()['sources']['embeddings_provider'])->toBe('env');
});

it('ignores keys that are not settings columns when saving', function (): void {
    $settings = resolver()->save([
        'chat_model' => 'gpt-5',
        'not_a_column' => 'nope',
    ]);

    expect($settings->chat_model)->toBe('gpt-5')
        ->and($settings->getAttributes())->not->toHaveKey('not_a_column');
});

it('returns a built provider array for the local sentinel', function (): void {
    config()->set('ai-kit.chat.provider', 'local');
    config()->set('ai-kit.embeddings.provider', 'local');
    config()->set('ai-kit.providers.local.driver', 'openai-compatible');
    config()->set('ai-kit.providers.local.url', 'http://localhost:11434/v1');
    config()->set('ai-kit.providers.local.models.chat', 'llama3.2');
    config()->set('ai-kit.providers.local.models.embeddings.default', 'nomic-embed-text');
    config()->set('ai-kit.providers.local.models.embeddings.dimensions', 768);

    $chat = resolver()->chatProvider();
    $embeddings = resolver()->embeddingsProvider();

    expect($chat)->toBeArray()
        ->and($chat[0])->toBeInstanceOf(Provider::class)
        ->and($chat[0]->name())->toBe(AiProviderResolver::LOCAL_PROVIDER_NAME)
        ->and($embeddings)->toBeArray()
        ->and($embeddings[0]->name())->toBe(AiProviderResolver::LOCAL_PROVIDER_NAME)
        ->and(resolver()->chatModel())->toBe('llama3.2')
        ->and(resolver()->embeddingsModel())->toBe('nomic-embed-text')
        ->and(resolver()->embeddingsDimensions())->toBe(768);
});

it('supports the ollama driver for the local provider', function (): void {
    config()->set('ai-kit.chat.provider', 'local');
    config()->set('ai-kit.providers.local.driver', 'ollama');
    config()->set('ai-kit.providers.local.url', 'http://localhost:11434');

    $provider = resolver()->chatProvider();

    expect($provider[0]->driver())->toBe('ollama');
});

it('throws a clear error when the local URL is missing', function (): void {
    config()->set('ai-kit.chat.provider', 'local');
    config()->set('ai-kit.providers.local.url', null);

    expect(fn () => resolver()->chatProvider())
        ->toThrow(RuntimeException::class, 'AI_KIT_LOCAL_URL');
});

it('masks the local key in effective()', function (): void {
    resolver()->save(['local_key' => 'super-secret-key']);

    $effective = resolver()->effective();

    expect(json_encode($effective, JSON_THROW_ON_ERROR))->not->toContain('super-secret-key')
        ->and($effective['local_key'])->not->toBe('super-secret-key')
        ->and($effective['local_key'])->toBe('supe********');
});

it('reset() returns every value to the env fallback', function (): void {
    config()->set('ai-kit.chat.provider', 'anthropic');

    resolver()->save(['chat_provider' => 'local', 'chat_model' => 'gpt-5']);
    expect(resolver()->chatProvider())->not->toBe('anthropic');

    resolver()->reset();

    expect(AiSettings::current())->toBeNull()
        ->and(resolver()->chatProvider())->toBe('anthropic')
        ->and(resolver()->chatModel())->toBe(config('ai-kit.chat.model'));
});

it('is used by RagAgent for chat provider and model', function (): void {
    resolver()->save([
        'chat_provider' => 'openrouter',
        'chat_model' => 'openai/gpt-5',
    ]);

    $agent = new RagAgent();

    expect($agent->chatProvider())->toBe('openrouter')
        ->and($agent->chatModel())->toBe('openai/gpt-5');
});

it('is used by AiSdkEmbedder for the effective embedding model', function (): void {
    config()->set('ai-kit.embeddings.model', 'text-embedding-3-small');

    resolver()->save(['embeddings_model' => 'nomic-embed-text']);

    expect(AiSdkEmbedder::fromConfig()->model())->toBe('nomic-embed-text');
});

it('is tolerant when the settings table does not exist', function (): void {
    Schema::dropIfExists('ai_kit_settings');

    expect(AiSettings::current())->toBeNull()
        ->and(resolver()->chatProvider())->toBe(config('ai-kit.chat.provider'))
        ->and(resolver()->effective()['source'])->toBe('env');

    resolver()->reset();
});

it('applies database overrides to config() and the SDK local provider', function (): void {
    resolver()->save([
        'chat_provider' => 'local',
        'chat_model' => 'gpt-5',
        'embeddings_provider' => 'local',
        'embeddings_model' => 'Qwen3-Embedding-4B-GGUF',
        'embeddings_dimensions' => 768,
        'local_driver' => 'openai-compatible',
        'local_url' => 'http://100.103.128.28/v1',
        'local_key' => 'super-secret-key',
        'local_chat_model' => 'Gemma-4-E4B-it-GGUF',
        'local_embeddings_model' => 'Qwen3-Embedding-4B-GGUF',
        'local_embeddings_dimensions' => 768,
    ]);

    resolver()->applyToConfig();

    expect(config('ai-kit.chat.provider'))->toBe('local')
        ->and(config('ai-kit.chat.model'))->toBe('gpt-5')
        ->and(config('ai-kit.embeddings.provider'))->toBe('local')
        ->and(config('ai-kit.embeddings.model'))->toBe('Qwen3-Embedding-4B-GGUF')
        ->and(config('ai-kit.embeddings.dimensions'))->toBe(768)
        ->and(config('ai.providers.local.driver'))->toBe('openai-compatible')
        ->and(config('ai.providers.local.url'))->toBe('http://100.103.128.28/v1')
        ->and(config('ai.providers.local.key'))->toBe('super-secret-key')
        ->and(config('ai.providers.local.models.text.default'))->toBe('Gemma-4-E4B-it-GGUF')
        ->and(config('ai.providers.local.models.embeddings.default'))->toBe('Qwen3-Embedding-4B-GGUF')
        ->and(config('ai.providers.local.models.embeddings.dimensions'))->toBe(768)
        ->and(config('ai.default'))->toBe('local')
        ->and(config('ai.default_for_embeddings'))->toBe('local');
});

it('leaves the SDK defaults alone when apply_to_sdk is disabled', function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.default_for_embeddings', 'openai');
    config()->set('ai-kit.features.apply_to_sdk', false);

    resolver()->save([
        'chat_provider' => 'local',
        'embeddings_provider' => 'local',
        'local_url' => 'http://127.0.0.1:8080/v1',
    ]);
    resolver()->applyToConfig();

    expect(config('ai.default'))->toBe('openai')
        ->and(config('ai.default_for_embeddings'))->toBe('openai')
        ->and(config('ai-kit.chat.provider'))->toBe('local')
        ->and(config('ai.providers.local.url'))->toBe('http://127.0.0.1:8080/v1');
});

it('writes the real local key to config but keeps effective() masked', function (): void {
    resolver()->save([
        'local_url' => 'http://127.0.0.1:8080/v1',
        'local_key' => 'super-secret-key',
    ]);
    resolver()->applyToConfig();

    expect(config('ai.providers.local.key'))->toBe('super-secret-key')
        ->and(resolver()->effective()['local_key'])->toBe('supe********')
        ->and(json_encode(resolver()->effective(), JSON_THROW_ON_ERROR))->not->toContain('super-secret-key');
});

it('does not register a partial local provider without a URL', function (): void {
    config()->set('ai.providers.local', null);
    resolver()->save(['chat_provider' => 'local']);
    config()->set('ai-kit.providers.local.url', null);

    resolver()->applyToConfig();

    expect(config('ai.providers.local'))->toBeNull()
        ->and(config('ai-kit.chat.provider'))->toBe('local');
});

it('restores the env-backed config after reset in the same process', function (): void {
    $envProvider = config('ai-kit.chat.provider');
    $envLocalUrl = config('ai.providers.local.url');

    resolver()->save([
        'chat_provider' => 'local',
        'local_url' => 'http://192.0.2.20/v1',
    ]);
    resolver()->applyToConfig();

    expect(config('ai-kit.chat.provider'))->toBe('local');

    resolver()->reset();
    resolver()->applyToConfig();

    expect(config('ai-kit.chat.provider'))->toBe($envProvider)
        ->and(config('ai.providers.local.url'))->toBe($envLocalUrl)
        ->and(config('ai.default'))->toBe($envProvider);
});

it('does not bake database values into the config cache', function (): void {
    $originalArgv = $_SERVER['argv'] ?? null;
    $envProvider = config('ai-kit.chat.provider');
    $envLocalUrl = config('ai.providers.local.url');

    resolver()->save([
        'chat_provider' => 'local',
        'local_url' => 'http://192.0.2.10/v1',
    ]);

    try {
        // ConfigCacheCommand boots a fresh app in the same process, with the
        // original artisan argv still in $_SERVER.
        $_SERVER['argv'] = ['artisan', 'config:cache'];
        (new AiKitServiceProvider($this->app))->boot();

        expect(config('ai-kit.chat.provider'))->toBe($envProvider)
            ->and(config('ai.providers.local.url'))->toBe($envLocalUrl);
    } finally {
        if ($originalArgv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $originalArgv;
        }
    }

    // The row is still there and a normal request does apply it.
    resolver()->applyToConfig();

    expect(config('ai-kit.chat.provider'))->toBe('local')
        ->and(config('ai.providers.local.url'))->toBe('http://192.0.2.10/v1');
});
