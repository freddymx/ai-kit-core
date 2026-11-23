<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Providers\Provider;
use RuntimeException;
use SolutionForest\AiKit\Agents\RagAgent;
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
