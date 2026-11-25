<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SolutionForest\AiKit\Facades\AiKit;
use SolutionForest\AiKit\Support\AiProviderResolver;
use SolutionForest\AiKit\Support\AiSettings;

uses(RefreshDatabase::class);

function tenantResolver(): AiProviderResolver
{
    return app(AiProviderResolver::class);
}

it('scopes saved settings to the active tenant and hides them from other tenants', function (): void {
    config()->set('ai-kit.tenancy.enabled', true);

    $resolver = tenantResolver();
    $envProvider = config('ai-kit.chat.provider');

    AiKit::withTenant('acme', function () use ($resolver): void {
        $resolver->save(['chat_provider' => 'openrouter', 'chat_model' => 'acme-model']);
        $resolver->reapply();

        expect(AiSettings::current()?->tenant_id)->toBe('acme')
            ->and(AiSettings::current()?->chat_provider)->toBe('openrouter')
            ->and(config('ai-kit.chat.provider'))->toBe('openrouter');
    });

    AiKit::withTenant('globex', function () use ($resolver, $envProvider): void {
        expect(AiSettings::current())->toBeNull()
            ->and($resolver->chatProvider())->toBe($envProvider)
            ->and(config('ai-kit.chat.provider'))->toBe($envProvider);
    });

    expect(DB::table('ai_kit_settings')->count())->toBe(1)
        ->and(DB::table('ai_kit_settings')->value('tenant_id'))->toBe('acme');
});

it('re-applies config when the tenant changes and restores the previous tenant in finally', function (): void {
    config()->set('ai-kit.tenancy.enabled', true);

    $resolver = tenantResolver();
    $envProvider = config('ai-kit.chat.provider');

    AiKit::withTenant('acme', function () use ($resolver): void {
        $resolver->save(['chat_provider' => 'openrouter']);
        $resolver->reapply();
    });

    // setTenant() re-publishes: leaving acme restores its own values, entering
    // globex (no row) publishes the env-backed defaults.
    AiKit::withTenant('globex', function () use ($resolver, $envProvider): void {
        expect(config('ai-kit.chat.provider'))->toBe($envProvider)
            ->and($resolver->chatProvider())->toBe($envProvider);
    });

    expect(AiKit::tenant())->toBeNull()
        ->and(config('ai-kit.chat.provider'))->toBe($envProvider);

    try {
        AiKit::withTenant('acme', function (): void {
            throw new LogicException('boom');
        });
    } catch (LogicException) {
        // Expected: the finally block must still restore the previous tenant.
    }

    expect(AiKit::tenant())->toBeNull();
});

it('does not publish database values or allow writes without a tenant', function (): void {
    config()->set('ai-kit.tenancy.enabled', true);

    $resolver = tenantResolver();
    $env = [
        'provider' => config('ai-kit.chat.provider'),
        'model' => config('ai-kit.chat.model'),
        'local_url' => config('ai.providers.local.url'),
        'default' => config('ai.default'),
    ];

    DB::table('ai_kit_settings')->insert([
        'chat_provider' => 'openrouter',
        'chat_model' => 'must-not-leak',
        'local_url' => 'http://192.0.2.99/v1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver->applyToConfig();

    expect(config('ai-kit.chat.provider'))->toBe($env['provider'])
        ->and(config('ai-kit.chat.model'))->toBe($env['model'])
        ->and(config('ai.providers.local.url'))->toBe($env['local_url'])
        ->and(config('ai.default'))->toBe($env['default'])
        ->and(AiSettings::current())->toBeNull();

    expect(fn () => $resolver->save(['chat_provider' => 'openrouter']))
        ->toThrow(RuntimeException::class, 'ai-kit tenant');
    expect(fn () => $resolver->reset())->toThrow(RuntimeException::class, 'ai-kit tenant');

    // The guard above must not delete the row belonging to some tenant.
    expect(DB::table('ai_kit_settings')->count())->toBe(1);
});

it('keeps the global row and previous behaviour when tenancy is disabled', function (): void {
    config()->set('ai-kit.tenancy.enabled', false);

    $resolver = tenantResolver();
    $settings = $resolver->save(['chat_provider' => 'openrouter']);
    $resolver->reapply();

    expect($settings->tenant_id)->toBeNull()
        ->and(AiSettings::current()?->chat_provider)->toBe('openrouter')
        ->and(config('ai-kit.chat.provider'))->toBe('openrouter')
        ->and(DB::table('ai_kit_settings')->count())->toBe(1);

    $resolver->reset();

    expect(AiSettings::current())->toBeNull()
        ->and(DB::table('ai_kit_settings')->count())->toBe(0);
});

it('never poisons the env baseline across tenants and resets', function (): void {
    config()->set('ai-kit.tenancy.enabled', true);

    $resolver = tenantResolver();
    $envProvider = config('ai-kit.chat.provider');

    AiKit::withTenant('acme', function () use ($resolver, $envProvider): void {
        $resolver->save(['chat_provider' => 'local', 'chat_model' => 'acme-model']);
        $resolver->reapply();
        expect(config('ai-kit.chat.provider'))->toBe('local');

        $resolver->reset();
        $resolver->reapply();
        expect(config('ai-kit.chat.provider'))->toBe($envProvider);
    });

    AiKit::withTenant('globex', function () use ($resolver): void {
        $resolver->save(['chat_provider' => 'openrouter', 'chat_model' => 'globex-model']);
        $resolver->reapply();
        expect(config('ai-kit.chat.provider'))->toBe('openrouter');
    });

    AiKit::withTenant('acme', function () use ($resolver, $envProvider): void {
        expect(AiSettings::current())->toBeNull()
            ->and($resolver->chatProvider())->toBe($envProvider);

        // Resetting acme (no row) must not delete globex's row.
        $resolver->reset();
        $resolver->reapply();
        expect(config('ai-kit.chat.provider'))->toBe($envProvider);
    });

    expect(DB::table('ai_kit_settings')->where('tenant_id', 'globex')->count())->toBe(1);
});

it('restores env config when a tenant scope ends with no active tenant', function (): void {
    config()->set('ai-kit.tenancy.enabled', true);

    $resolver = tenantResolver();
    $envProvider = config('ai-kit.chat.provider');
    $envDefault = config('ai.default');
    $envUrl = config('ai.providers.local.url');

    AiKit::withTenant('acme', function () use ($resolver): void {
        $resolver->save([
            'chat_provider' => 'openrouter',
            'local_url' => 'http://192.0.2.99/v1',
        ]);
        $resolver->reapply();

        expect(config('ai-kit.chat.provider'))->toBe('openrouter')
            ->and(config('ai.providers.local.url'))->toBe('http://192.0.2.99/v1');
    });

    // withTenant() restored the previous (null) tenant: no tenant's values
    // may remain in the process-global config repository.
    expect(config('ai-kit.chat.provider'))->toBe($envProvider)
        ->and(config('ai.default'))->toBe($envDefault)
        ->and(config('ai.providers.local.url'))->toBe($envUrl);
});
