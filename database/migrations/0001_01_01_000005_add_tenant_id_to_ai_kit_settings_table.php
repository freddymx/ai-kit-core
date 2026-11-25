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

    /**
     * One settings row per tenant when tenancy is enabled. Existing installs
     * keep their single global row (tenant_id = null), so 000004 stays untouched.
     */
    public function up(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            $table->string('tenant_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
