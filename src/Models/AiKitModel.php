<?php

declare(strict_types=1);

namespace SolutionForest\AiKit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model for ai-kit tables. Resolves the configured connection and the
 * "ai_kit_" table prefix so consumers can move the tables to another DB.
 */
abstract class AiKitModel extends Model
{
    /** The unprefixed table name, set by each child model. */
    protected string $baseTable = '';

    public function getConnectionName(): ?string
    {
        return config('ai-kit.database.connection') ?? parent::getConnectionName();
    }

    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }

        $prefix = (string) config('ai-kit.database.prefix', 'ai_kit_');

        return $prefix . $this->baseTable;
    }
}
