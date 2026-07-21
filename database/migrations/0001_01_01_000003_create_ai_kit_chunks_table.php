<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function connection(): ?string
    {
        return config('ai-kit.database.connection');
    }

    private function prefix(): string
    {
        return config('ai-kit.database.prefix', 'ai_kit_');
    }

    private function isPgVector(): bool
    {
        return config('ai-kit.retrieval.driver') === 'pgvector';
    }

    public function up(): void
    {
        $prefix = $this->prefix();
        $table = $prefix . 'chunks';

        Schema::connection($this->connection())->create($table, function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->foreignId('collection_id')->constrained($prefix . 'collections')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained($prefix . 'documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            // Portable source-of-truth embedding (JSON array of floats).
            $table->json('embedding')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('token_count')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'chunk_index']);
        });

        if ($this->isPgVector()) {
            $this->addPgVectorColumn($table);
        }
    }

    private function addPgVectorColumn(string $table): void
    {
        $dimensions = (int) config('ai-kit.embeddings.dimensions', 1536);
        $index = config('ai-kit.retrieval.pgvector.index', 'hnsw') === 'ivfflat' ? 'ivfflat' : 'hnsw';
        $connection = DB::connection($this->connection());

        $connection->statement('CREATE EXTENSION IF NOT EXISTS vector');
        $connection->statement("ALTER TABLE {$table} ADD COLUMN embedding_vector vector({$dimensions})");
        $connection->statement(
            "CREATE INDEX {$table}_embedding_vector_idx ON {$table} USING {$index} (embedding_vector vector_cosine_ops)"
        );
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->prefix() . 'chunks');
    }
};
