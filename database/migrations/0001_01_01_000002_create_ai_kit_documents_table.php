<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->prefix() . 'documents', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->foreignId('collection_id')->constrained($this->prefix() . 'collections')->cascadeOnDelete();
            $table->string('title');
            $table->string('source_path')->nullable();
            $table->string('mime')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->prefix() . 'documents');
    }
};
