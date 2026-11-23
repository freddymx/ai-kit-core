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

    private function table(): string
    {
        return config('ai-kit.database.prefix', 'ai_kit_') . 'settings';
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('chat_provider')->nullable();
            $table->string('chat_model')->nullable();
            $table->string('embeddings_provider')->nullable();
            $table->string('embeddings_model')->nullable();
            $table->integer('embeddings_dimensions')->nullable();
            $table->string('local_driver')->nullable();
            $table->string('local_url')->nullable();
            // Encrypted cast — ciphertext needs more than 255 bytes.
            $table->text('local_key')->nullable();
            $table->string('local_chat_model')->nullable();
            $table->string('local_embeddings_model')->nullable();
            $table->integer('local_embeddings_dimensions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
