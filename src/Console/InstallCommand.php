<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * One-command setup: publish config + migrations, scaffold the app's agent,
 * optionally migrate, and optionally seed the demo.
 */
final class InstallCommand extends Command
{
    protected $signature = 'ai-kit:install';

    protected $description = 'Install ai-kit: publish config, migrations, and a starter agent';

    public function handle(): int
    {
        note('Installing ai-kit…');

        $this->callSilently('vendor:publish', [
            '--tag' => 'ai-kit-config',
            '--force' => true,
        ]);
        $this->components->task('Published config (config/ai-kit.php)');

        $this->callSilently('vendor:publish', ['--tag' => 'ai-kit-migrations']);
        $this->components->task('Published migrations');

        $this->publishConversationMigrations();

        $hasUi = confirm('Is solutionforest/ai-kit-ui installed (the Livewire chat UI)?', default: false);
        $hasFilament = confirm('Is solutionforest/ai-kit-filament installed (the admin panel)?', default: false);

        if ($hasUi) {
            $this->callSilently('vendor:publish', ['--tag' => 'ai-kit-ui-assets']);
            $this->components->task('Published UI assets');
        }

        $this->publishAgentStub();

        $this->configureAi();

        if (confirm('Run migrations now?', default: true)) {
            $this->call('migrate');
        }

        if (confirm('Seed a demo knowledge base and sample documents now?', default: true)) {
            $this->call('ai-kit:demo');
        }

        note('ai-kit installed.');
        $this->summary($hasUi, $hasFilament);

        return self::SUCCESS;
    }

    /**
     * Sensible default chat model per provider.
     *
     * @var array<string, string>
     */
    private const CHAT_DEFAULTS = [
        'anthropic' => 'claude-sonnet-5',
        'openai' => 'gpt-5',
        'openrouter' => 'anthropic/claude-sonnet-5',
        'gemini' => 'gemini-2.5-pro',
        'groq' => 'llama-3.3-70b',
        'mistral' => 'mistral-large-latest',
        'deepseek' => 'deepseek-chat',
        'xai' => 'grok-4',
        'ollama' => 'llama3.2',
        'openai-compatible' => 'llama3.2',
        // Sentinel: routes through ai-kit's on-demand local provider.
        'local' => 'llama3.2',
    ];

    /**
     * Sensible default embedding model per provider.
     *
     * @var array<string, string>
     */
    private const EMBED_DEFAULTS = [
        'openai' => 'text-embedding-3-small',
        'voyageai' => 'voyage-3',
        'jina' => 'jina-embeddings-v3',
        'cohere' => 'embed-english-v3.0',
        'gemini' => 'gemini-embedding-001',
        'mistral' => 'mistral-embed',
        'ollama' => 'nomic-embed-text',
        'openai-compatible' => 'nomic-embed-text',
        // Sentinel: routes through ai-kit's on-demand local provider.
        'local' => 'nomic-embed-text',
    ];

    /**
     * Selections that mean "a local server". They are normalised to the
     * `local` sentinel in .env so AiProviderResolver builds the on-demand
     * provider with the chosen driver.
     *
     * @var list<string>
     */
    private const LOCAL_PROVIDERS = ['local', 'ollama', 'openai-compatible'];

    /** @var array<string, string> */
    private const LOCAL_DRIVER_BY_PROVIDER = [
        'local' => 'openai-compatible',
        'ollama' => 'ollama',
        'openai-compatible' => 'openai-compatible',
    ];

    /** @var array<string, string> */
    private const LOCAL_URL_BY_DRIVER = [
        'ollama' => 'http://localhost:11434',
        'openai-compatible' => 'http://localhost:11434/v1',
    ];

    /**
     * Provider → API key env var. Providers absent from this map need no key (e.g. ollama).
     *
     * @var array<string, string>
     */
    private const PROVIDER_ENV_KEYS = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
        'groq' => 'GROQ_API_KEY',
        'mistral' => 'MISTRAL_API_KEY',
        'deepseek' => 'DEEPSEEK_API_KEY',
        'xai' => 'XAI_API_KEY',
        'cohere' => 'COHERE_API_KEY',
        'voyageai' => 'VOYAGEAI_API_KEY',
        'jina' => 'JINA_API_KEY',
    ];

    /**
     * Interactively collect AI provider configuration and write it to the app's .env.
     */
    /**
     * The default ai-kit .env block, appended verbatim on first install so a
     * fresh app always has the configuration scaffold (even if the user skips
     * the interactive prompts and edits .env by hand later).
     */
    private const AI_ENV_BLOCK = <<<'ENV'

        # ---- ai-kit configuration ----
        AI_KIT_RETRIEVER=sqlite
        AI_KIT_MODE=tool
        AI_KIT_CHAT_PROVIDER=openai
        AI_KIT_CHAT_MODEL=gpt-5
        AI_KIT_EMBED_PROVIDER=openai
        AI_KIT_EMBED_MODEL=text-embedding-3-small

        # Add ONE provider key below (uncomment + paste your key):
        # OPENAI_API_KEY=
        # ANTHROPIC_API_KEY=
        # OPENROUTER_API_KEY=

        # ---- Local models: Ollama, LM Studio, vLLM, llama.cpp ----
        # Set AI_KIT_CHAT_PROVIDER / AI_KIT_EMBED_PROVIDER to "local" to use them.
        # AI_KIT_LOCAL_DRIVER=openai-compatible
        # AI_KIT_LOCAL_URL=http://localhost:11434/v1
        # AI_KIT_LOCAL_KEY=
        # AI_KIT_LOCAL_CHAT_MODEL=llama3.2
        # AI_KIT_LOCAL_EMBED_MODEL=nomic-embed-text
        # AI_KIT_LOCAL_EMBED_DIMENSIONS=768
        ENV;

    /**
     * Append the ai-kit .env scaffold once, if it is not already present.
     * Idempotent: detects the block by the AI_KIT_CHAT_PROVIDER marker.
     */
    public static function withAiScaffold(string $contents): string
    {
        if (str_contains($contents, 'AI_KIT_CHAT_PROVIDER')) {
            return $contents;
        }

        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        return $contents . self::AI_ENV_BLOCK . "\n";
    }

    private function configureAi(): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            $this->components->warn('.env not found — creating one to store your AI configuration.');
            File::put($envPath, '');
        }

        // Always ensure the config scaffold exists, whether or not the user runs
        // the interactive prompts below.
        $scaffolded = self::withAiScaffold(File::get($envPath));
        if ($scaffolded !== File::get($envPath)) {
            File::put($envPath, $scaffolded);
            $this->components->task('Added ai-kit configuration to .env');
        }

        if (! confirm('Configure your AI providers interactively now? (otherwise edit .env by hand)', default: true)) {
            return;
        }

        $chatProvider = select(
            'Which provider should generate chat answers?',
            array_keys(self::CHAT_DEFAULTS),
            default: 'anthropic',
        );
        $chatModel = text('Chat model?', default: self::CHAT_DEFAULTS[$chatProvider] ?? '');

        $embedProvider = select(
            'Which provider should generate embeddings?',
            array_keys(self::EMBED_DEFAULTS),
            default: 'openai',
        );
        $embedModel = text('Embedding model?', default: self::EMBED_DEFAULTS[$embedProvider] ?? '');
        $embedDimensions = text(
            'Embedding dimensions?',
            default: in_array($embedProvider, self::LOCAL_PROVIDERS, true) ? '768' : '1536',
        );

        $pairs = [
            'AI_KIT_CHAT_PROVIDER' => $chatProvider,
            'AI_KIT_CHAT_MODEL' => $chatModel,
            'AI_KIT_EMBED_PROVIDER' => $embedProvider,
            'AI_KIT_EMBED_MODEL' => $embedModel,
            'AI_KIT_EMBED_DIMENSIONS' => $embedDimensions,
        ];

        $this->configureLocalProvider($chatProvider, $embedProvider, $chatModel, $embedModel, $pairs);

        $envPath = base_path('.env');
        $contents = File::exists($envPath) ? File::get($envPath) : '';

        // Prompt once per distinct provider that needs a key and isn't already set.
        foreach (array_unique([$chatProvider, $embedProvider]) as $provider) {
            $envKey = self::PROVIDER_ENV_KEYS[$provider] ?? null;

            if ($envKey === null) {
                continue; // provider needs no key (e.g. ollama)
            }

            if ($this->envKeyIsSet($envKey, $contents)) {
                $this->components->info("{$envKey} is already set — leaving it as is.");

                continue;
            }

            $value = password("API key for {$provider}? (leave blank to set later in .env)");

            if ($value !== '' && $value !== null) {
                $pairs[$envKey] = $value;
            }
        }

        if (! File::exists($envPath)) {
            $this->components->warn('.env not found — creating one to store your AI configuration.');
        }

        File::put($envPath, self::upsertEnv($contents, $pairs));
        $this->components->task('Wrote AI provider settings to .env');
    }

    /**
     * Ask for the shared local-server settings when either side is local and
     * write the AI_KIT_LOCAL_* keys. The provider selection is normalised to
     * the `local` sentinel so AiProviderResolver builds the on-demand provider
     * with the chosen driver.
     *
     * @param  array<string, string>  $pairs
     */
    private function configureLocalProvider(
        string $chatProvider,
        string $embedProvider,
        string $chatModel,
        string $embedModel,
        array &$pairs,
    ): void {
        $local = array_values(array_filter(
            [$chatProvider, $embedProvider],
            fn (string $provider): bool => in_array($provider, self::LOCAL_PROVIDERS, true),
        ));

        if ($local === []) {
            return;
        }

        $driver = select(
            'Which local server driver?',
            array_keys(self::LOCAL_URL_BY_DRIVER),
            default: self::LOCAL_DRIVER_BY_PROVIDER[$local[0]] ?? 'openai-compatible',
        );
        $url = text(
            'Local server URL?',
            default: self::LOCAL_URL_BY_DRIVER[$driver] ?? self::LOCAL_URL_BY_DRIVER['openai-compatible'],
        );
        $localChatModel = text(
            'Local chat model?',
            default: in_array($chatProvider, self::LOCAL_PROVIDERS, true) ? $chatModel : (self::CHAT_DEFAULTS['local'] ?? 'llama3.2'),
        );
        $localEmbeddingsModel = text(
            'Local embedding model?',
            default: in_array($embedProvider, self::LOCAL_PROVIDERS, true) ? $embedModel : (self::EMBED_DEFAULTS['local'] ?? 'nomic-embed-text'),
        );
        $localDimensions = text('Local embedding dimensions?', default: '768');
        $localKey = password('Local API key? (optional — leave blank for servers without auth)');

        $pairs['AI_KIT_LOCAL_DRIVER'] = $driver;
        $pairs['AI_KIT_LOCAL_URL'] = $url;
        $pairs['AI_KIT_LOCAL_CHAT_MODEL'] = $localChatModel;
        $pairs['AI_KIT_LOCAL_EMBED_MODEL'] = $localEmbeddingsModel;
        $pairs['AI_KIT_LOCAL_EMBED_DIMENSIONS'] = $localDimensions;

        if ($localKey !== '' && $localKey !== null) {
            $pairs['AI_KIT_LOCAL_KEY'] = $localKey;
        }

        if (in_array($chatProvider, self::LOCAL_PROVIDERS, true)) {
            $pairs['AI_KIT_CHAT_PROVIDER'] = 'local';
        }

        if (in_array($embedProvider, self::LOCAL_PROVIDERS, true)) {
            $pairs['AI_KIT_EMBED_PROVIDER'] = 'local';
        }
    }

    /**
     * Determine whether an env var already has a non-empty value in the current
     * environment or in the given .env contents.
     */
    private function envKeyIsSet(string $key, string $contents): bool
    {
        $current = env($key);
        if ($current !== null && $current !== '') {
            return true;
        }

        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $m)) {
            return trim($m[1], " \t\"'") !== '';
        }

        return false;
    }

    /**
     * Upsert key=value pairs into .env file contents. Existing keys are replaced
     * in place; missing keys are appended. Other lines are preserved. Values
     * containing spaces (or other whitespace) are double-quoted.
     *
     * @param  array<string, string>  $pairs
     */
    public static function upsertEnv(string $contents, array $pairs): string
    {
        foreach ($pairs as $key => $value) {
            $line = $key . '=' . self::formatEnvValue($value);
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = preg_replace($pattern, $line, $contents, 1);

                continue;
            }

            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $contents .= $line . "\n";
        }

        return $contents;
    }

    /**
     * Quote an env value if it contains whitespace; otherwise return it as-is.
     */
    private static function formatEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/\s/', $value) !== 1) {
            return $value;
        }

        return '"' . str_replace('"', '\"', $value) . '"';
    }

    /**
     * Publish the laravel/ai SDK's conversation migrations (agent_conversations,
     * agent_conversation_messages) so RagAgent conversation persistence and the
     * Filament conversations resource/widget work. Guarded so re-running install
     * does not create duplicate timestamped copies.
     */
    private function publishConversationMigrations(): void
    {
        $existing = glob(database_path('migrations/*_create_agent_conversations_table.php')) ?: [];

        if ($existing !== []) {
            return;
        }

        $this->callSilently('vendor:publish', [
            '--provider' => 'Laravel\\Ai\\AiServiceProvider',
        ]);
        $this->components->task('Published AI SDK conversation migrations');
    }

    private function publishAgentStub(): void
    {
        $target = app_path('Ai/SupportAgent.php');

        if (File::exists($target)) {
            $this->components->warn('App\\Ai\\SupportAgent already exists — skipped.');

            return;
        }

        File::ensureDirectoryExists(dirname($target));
        File::copy(__DIR__ . '/../../resources/stubs/SupportAgent.php.stub', $target);
        $this->components->task('Created app/Ai/SupportAgent.php');

        note("Set AI_KIT_AGENT=App\\\\Ai\\\\SupportAgent in your .env to use it.");
    }

    private function summary(bool $hasUi, bool $hasFilament): void
    {
        $lines = ['Next steps:'];
        $lines[] = '  • Set your embedding + chat provider keys in .env (see config/ai-kit.php).';
        $lines[] = '  • Ingest your own docs: php artisan ai-kit:ingest path/to/docs --collection=default';

        if ($hasUi) {
            $lines[] = '  • Drop the chat UI into a Blade view: <livewire:ai-kit.chat collection="demo" />';
        }
        if ($hasFilament) {
            $lines[] = '  • Open the Filament panel → Playground to demo the chat to a client.';
        }

        note(implode(PHP_EOL, $lines));
    }
}
