<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Support;

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\Provider as ProviderContract;
use Laravel\Ai\Enums\Lab;
use RuntimeException;

/**
 * Single source of truth for which provider/model ai-kit uses.
 *
 * Every value resolves as: database row (when not null) → config/ai-kit.php
 * (which is .env backed). The sentinel provider "local" is expanded into an
 * on-demand OpenAI-compatible Provider built with `Ai::build()`, so callers
 * never see the string "local" — they get a Provider object the AI SDK
 * understands for both chat and embeddings.
 *
 * No database access happens at boot: resolution is lazy, per method call.
 */
final class AiProviderResolver
{
    /** Internal name of the on-demand provider. Not a Lab, not in ai.providers.*. */
    public const LOCAL_PROVIDER_NAME = 'ai_kit_local';

    private const CONFIG_MAP = [
        'chat_provider' => 'ai-kit.chat.provider',
        'chat_model' => 'ai-kit.chat.model',
        'embeddings_provider' => 'ai-kit.embeddings.provider',
        'embeddings_model' => 'ai-kit.embeddings.model',
        'embeddings_dimensions' => 'ai-kit.embeddings.dimensions',
        'local_driver' => 'ai-kit.providers.local.driver',
        'local_url' => 'ai-kit.providers.local.url',
        'local_key' => 'ai-kit.providers.local.key',
        'local_chat_model' => 'ai-kit.providers.local.models.chat',
        'local_embeddings_model' => 'ai-kit.providers.local.models.embeddings.default',
        'local_embeddings_dimensions' => 'ai-kit.providers.local.models.embeddings.dimensions',
    ];

    private const INTEGER_KEYS = ['embeddings_dimensions', 'local_embeddings_dimensions'];

    private const ALLOWED_KEYS = [
        'chat_provider', 'chat_model',
        'embeddings_provider', 'embeddings_model', 'embeddings_dimensions',
        'local_driver', 'local_url', 'local_key',
        'local_chat_model', 'local_embeddings_model', 'local_embeddings_dimensions',
    ];

    public function chatProvider(): Lab|array|string|null
    {
        $values = $this->rawEffective();
        $provider = $this->normaliseProvider($values['chat_provider']);

        if ($provider !== null && strcasecmp($provider, 'local') === 0) {
            return [$this->buildLocalProvider($values)];
        }

        return $provider;
    }

    public function chatModel(): ?string
    {
        $values = $this->rawEffective();

        if ($this->isLocal($values['chat_provider'])) {
            return $this->stringOrNull($values['local_chat_model']) ?? $this->stringOrNull($values['chat_model']);
        }

        return $this->stringOrNull($values['chat_model']);
    }

    public function embeddingsProvider(): Lab|array|string|null
    {
        $values = $this->rawEffective();
        $provider = $this->normaliseProvider($values['embeddings_provider']);

        if ($provider !== null && strcasecmp($provider, 'local') === 0) {
            return [$this->buildLocalProvider($values)];
        }

        return $provider;
    }

    public function embeddingsModel(): ?string
    {
        $values = $this->rawEffective();

        if ($this->isLocal($values['embeddings_provider'])) {
            return $this->stringOrNull($values['local_embeddings_model']) ?? $this->stringOrNull($values['embeddings_model']);
        }

        return $this->stringOrNull($values['embeddings_model']);
    }

    public function embeddingsDimensions(): ?int
    {
        $values = $this->rawEffective();

        if ($this->isLocal($values['embeddings_provider']) && $values['local_embeddings_dimensions'] !== null) {
            return (int) $values['local_embeddings_dimensions'];
        }

        return $values['embeddings_dimensions'] !== null
            ? (int) $values['embeddings_dimensions']
            : null;
    }

    /**
     * Effective values for display (e.g. in the Filament settings page), with
     * the local key masked and a per-value `env` / `database` origin.
     *
     * @return array<string, mixed>
     */
    public function effective(): array
    {
        $values = $this->rawEffective();
        $sources = $this->sources();

        $values['local_key'] = $this->maskKey($this->stringOrNull($values['local_key']));
        $values['source'] = $sources !== [] && in_array('database', $sources, true) ? 'database' : 'env';
        $values['sources'] = $sources;

        return $values;
    }

    /**
     * Upsert the single settings row. Unknown keys are ignored so a Filament
     * form can hand over its whole state without accidentally writing columns
     * that do not exist.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): AiSettings
    {
        $payload = [];

        foreach ($values as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $payload[$key] = in_array($key, self::INTEGER_KEYS, true) && $value !== null
                ? (int) $value
                : $value;
        }

        $settings = AiSettings::current() ?? new AiSettings();
        $settings->fill($payload)->save();

        return $settings;
    }

    /**
     * Delete the settings row, returning every value to its .env default.
     */
    public function reset(): void
    {
        if (! AiSettings::tableExists()) {
            return;
        }

        AiSettings::query()->delete();
    }

    /**
     * Resolve every value without masking — internal use only. The public
     * effective() masks the key before it reaches a UI or a log.
     *
     * @return array<string, mixed>
     */
    private function rawEffective(): array
    {
        $settings = AiSettings::current();
        $values = [];

        foreach (self::CONFIG_MAP as $key => $configKey) {
            $fromDatabase = $settings?->{$key};

            $value = $fromDatabase !== null
                ? $fromDatabase
                : config($configKey);

            $values[$key] = in_array($key, self::INTEGER_KEYS, true) && $value !== null
                ? (int) $value
                : $value;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        $settings = AiSettings::current();
        $sources = [];

        foreach (self::CONFIG_MAP as $key => $configKey) {
            $sources[$key] = $settings?->{$key} !== null ? 'database' : 'env';
        }

        return $sources;
    }

    private function isLocal(mixed $provider): bool
    {
        return is_string($provider) && strcasecmp($provider, 'local') === 0;
    }

    private function normaliseProvider(mixed $provider): Lab|array|string|null
    {
        if ($provider instanceof Lab || is_array($provider)) {
            return $provider;
        }

        if ($provider === null || $provider === '') {
            return null;
        }

        return (string) $provider;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Build the on-demand provider the SDK will use for both chat and
     * embeddings when the configured provider is the "local" sentinel.
     *
     * @param  array<string, mixed>  $values
     */
    private function buildLocalProvider(array $values): ProviderContract
    {
        $url = $this->stringOrNull($values['local_url']);

        if ($url === null) {
            throw new RuntimeException(
                'The ai-kit local provider has no URL configured. Set AI_KIT_LOCAL_URL in .env '
                . '(or the "Local URL" field in the Filament AI settings) to the base URL of your '
                . 'OpenAI-compatible server, e.g. http://localhost:11434/v1 for Ollama.'
            );
        }

        $driver = $this->stringOrNull($values['local_driver']) ?? 'openai-compatible';

        return Ai::build([
            'name' => self::LOCAL_PROVIDER_NAME,
            'driver' => $driver,
            'url' => $url,
            'key' => $this->stringOrNull($values['local_key']),
            'models' => [
                // The SDK reads models.text.default when no model is passed
                // explicitly (OpenAiCompatibleProvider::defaultTextModel()).
                'text' => [
                    'default' => $this->stringOrNull($values['local_chat_model']),
                ],
                'chat' => $this->stringOrNull($values['local_chat_model']),
                'embeddings' => [
                    'default' => $this->stringOrNull($values['local_embeddings_model']),
                    'dimensions' => (int) ($values['local_embeddings_dimensions'] ?? 768),
                ],
            ],
        ]);
    }

    private function maskKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return $key;
        }

        if (strlen($key) <= 4) {
            return '****';
        }

        return substr($key, 0, 4) . str_repeat('*', min(8, strlen($key) - 4));
    }
}
