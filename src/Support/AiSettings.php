<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Support;

use Illuminate\Support\Facades\Schema;
use SolutionForest\AiKit\AiKit;
use SolutionForest\AiKit\Models\AiKitModel;
use SolutionForest\AiKit\Models\Concerns\BelongsToTenant;
use Throwable;

/**
 * The provider overrides edited from the Filament settings page: one row per
 * tenant when tenancy is enabled, a single global row otherwise.
 *
 * Every column is nullable: null means "fall back to config/ai-kit.php (.env)".
 * current() stays quiet when the table has not been migrated yet, so upgrading
 * an app cannot break boot or a plain `new RagAgent()` before `migrate`.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string|null $chat_provider
 * @property string|null $chat_model
 * @property string|null $embeddings_provider
 * @property string|null $embeddings_model
 * @property int|null $embeddings_dimensions
 * @property string|null $local_driver
 * @property string|null $local_url
 * @property string|null $local_key
 * @property string|null $local_chat_model
 * @property string|null $local_embeddings_model
 * @property int|null $local_embeddings_dimensions
 */
class AiSettings extends AiKitModel
{
    use BelongsToTenant;

    protected string $baseTable = 'settings';

    protected $guarded = ['id'];

    protected $casts = [
        'embeddings_dimensions' => 'integer',
        'local_key' => 'encrypted',
        'local_embeddings_dimensions' => 'integer',
    ];

    /**
     * The settings row for the active tenant, or null when there is none —
     * including when the table does not exist yet.
     *
     * With tenancy enabled but no tenant set we must NOT query: the global
     * scope is a no-op in that case and `first()` would return another tenant's
     * row. With an active tenant the global scope already filters, so a plain
     * query is correct.
     */
    public static function current(): ?self
    {
        if (! self::tableExists()) {
            return null;
        }

        $context = app(AiKit::class);

        if ($context->tenancyEnabled() && ! $context->hasTenant()) {
            return null;
        }

        return static::query()->first();
    }

    /**
     * Tolerance for apps that have not published/run the core migrations yet.
     */
    public static function tableExists(): bool
    {
        try {
            $instance = new static();

            return Schema::connection($instance->getConnectionName())->hasTable($instance->getTable());
        } catch (Throwable) {
            return false;
        }
    }
}
