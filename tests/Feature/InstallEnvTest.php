<?php

declare(strict_types=1);

use SolutionForest\AiKit\Console\InstallCommand;

it('appends new keys when they are absent', function (): void {
    $original = "APP_NAME=AiKit\nAPP_ENV=testing\n";

    $result = InstallCommand::upsertEnv($original, [
        'AI_KIT_CHAT_PROVIDER' => 'anthropic',
        'AI_KIT_CHAT_MODEL' => 'claude-sonnet-5',
    ]);

    expect($result)->toContain("APP_NAME=AiKit")
        ->toContain("APP_ENV=testing")
        ->toContain("AI_KIT_CHAT_PROVIDER=anthropic")
        ->toContain("AI_KIT_CHAT_MODEL=claude-sonnet-5");
});

it('appends a trailing newline before appending when the file lacks one', function (): void {
    $original = 'APP_NAME=AiKit';

    $result = InstallCommand::upsertEnv($original, ['AI_KIT_EMBED_PROVIDER' => 'openai']);

    expect($result)->toBe("APP_NAME=AiKit\nAI_KIT_EMBED_PROVIDER=openai\n");
});

it('replaces an existing key value in place without duplicating', function (): void {
    $original = "AI_KIT_CHAT_PROVIDER=openai\nAPP_ENV=testing\n";

    $result = InstallCommand::upsertEnv($original, [
        'AI_KIT_CHAT_PROVIDER' => 'anthropic',
    ]);

    expect($result)->toBe("AI_KIT_CHAT_PROVIDER=anthropic\nAPP_ENV=testing\n");
    expect(substr_count($result, 'AI_KIT_CHAT_PROVIDER='))->toBe(1);
});

it('leaves unrelated lines untouched', function (): void {
    $original = "# comment\nAPP_NAME=AiKit\nDB_CONNECTION=sqlite\n\nMAIL_MAILER=log\n";

    $result = InstallCommand::upsertEnv($original, ['AI_KIT_EMBED_DIMENSIONS' => '1536']);

    expect($result)->toContain("# comment")
        ->toContain("APP_NAME=AiKit")
        ->toContain("DB_CONNECTION=sqlite")
        ->toContain("MAIL_MAILER=log")
        ->toContain("AI_KIT_EMBED_DIMENSIONS=1536");
});

it('quotes values that contain spaces', function (): void {
    $result = InstallCommand::upsertEnv('', [
        'AI_KIT_CHAT_MODEL' => 'mistral large latest',
    ]);

    expect($result)->toBe("AI_KIT_CHAT_MODEL=\"mistral large latest\"\n");
});

it('does not quote values without whitespace', function (): void {
    $result = InstallCommand::upsertEnv('', [
        'AI_KIT_CHAT_MODEL' => 'anthropic/claude-sonnet-5',
    ]);

    expect($result)->toBe("AI_KIT_CHAT_MODEL=anthropic/claude-sonnet-5\n");
});

it('appends the ai-kit env scaffold when it is absent', function (): void {
    $result = InstallCommand::withAiScaffold("APP_NAME=AiKit\n");

    expect($result)->toContain('# ---- ai-kit configuration ----')
        ->toContain('AI_KIT_RETRIEVER=sqlite')
        ->toContain('AI_KIT_MODE=tool')
        ->toContain('AI_KIT_CHAT_PROVIDER=openai')
        ->toContain('AI_KIT_EMBED_MODEL=text-embedding-3-small')
        ->toContain('# OPENAI_API_KEY=')
        ->toContain("APP_NAME=AiKit");
});

it('does not duplicate the scaffold when already present', function (): void {
    $once = InstallCommand::withAiScaffold("APP_NAME=AiKit\n");
    $twice = InstallCommand::withAiScaffold($once);

    expect($twice)->toBe($once)
        ->and(substr_count($twice, 'AI_KIT_CHAT_PROVIDER='))->toBe(1);
});

it('upserts the local provider keys', function (): void {
    $result = InstallCommand::upsertEnv("APP_NAME=AiKit\n", [
        'AI_KIT_LOCAL_DRIVER' => 'openai-compatible',
        'AI_KIT_LOCAL_URL' => 'http://localhost:11434/v1',
        'AI_KIT_LOCAL_CHAT_MODEL' => 'llama3.2',
        'AI_KIT_LOCAL_EMBED_MODEL' => 'nomic-embed-text',
        'AI_KIT_LOCAL_EMBED_DIMENSIONS' => '768',
        'AI_KIT_LOCAL_KEY' => 'secret',
    ]);

    expect($result)->toContain('AI_KIT_LOCAL_DRIVER=openai-compatible')
        ->toContain('AI_KIT_LOCAL_URL=http://localhost:11434/v1')
        ->toContain('AI_KIT_LOCAL_CHAT_MODEL=llama3.2')
        ->toContain('AI_KIT_LOCAL_EMBED_MODEL=nomic-embed-text')
        ->toContain('AI_KIT_LOCAL_EMBED_DIMENSIONS=768')
        ->toContain('AI_KIT_LOCAL_KEY=secret');

    $updated = InstallCommand::upsertEnv($result, [
        'AI_KIT_LOCAL_URL' => 'http://localhost:8000/v1',
    ]);

    expect($updated)->toContain('AI_KIT_LOCAL_URL=http://localhost:8000/v1')
        ->and(substr_count($updated, 'AI_KIT_LOCAL_URL='))->toBe(1);
});

it('documents the local provider keys in the env scaffold', function (): void {
    $result = InstallCommand::withAiScaffold("APP_NAME=AiKit\n");

    expect($result)->toContain('# AI_KIT_LOCAL_DRIVER=openai-compatible')
        ->toContain('# AI_KIT_LOCAL_URL=http://localhost:11434/v1')
        ->toContain('# AI_KIT_LOCAL_KEY=')
        ->toContain('# AI_KIT_LOCAL_CHAT_MODEL=llama3.2')
        ->toContain('# AI_KIT_LOCAL_EMBED_MODEL=nomic-embed-text')
        ->toContain('# AI_KIT_LOCAL_EMBED_DIMENSIONS=768');
});
