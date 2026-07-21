<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SolutionForest\AiKit\Http\Controllers\ChatController;
use SolutionForest\AiKit\Http\Controllers\CollectionController;
use SolutionForest\AiKit\Http\Controllers\ConversationController;
use SolutionForest\AiKit\Http\Controllers\UsageController;

/*
 * ai-kit JSON API. Loaded (with the configured prefix + middleware) only when
 * config('ai-kit.features.api') is true. See config/ai-kit.php → api.
 */

Route::post('chat', [ChatController::class, 'chat'])->name('ai-kit.api.chat');
Route::post('chat/stream', [ChatController::class, 'stream'])->name('ai-kit.api.chat.stream');
Route::post('search', [ChatController::class, 'search'])->name('ai-kit.api.search');

Route::get('collections', [CollectionController::class, 'index'])->name('ai-kit.api.collections.index');
Route::post('collections', [CollectionController::class, 'store'])->name('ai-kit.api.collections.store');
Route::get('collections/{slug}', [CollectionController::class, 'show'])->name('ai-kit.api.collections.show');
Route::get('collections/{slug}/documents', [CollectionController::class, 'documents'])->name('ai-kit.api.collections.documents');
Route::post('collections/{slug}/documents', [CollectionController::class, 'ingest'])->name('ai-kit.api.collections.ingest');

Route::get('conversations', [ConversationController::class, 'index'])->name('ai-kit.api.conversations.index');
Route::get('conversations/{id}', [ConversationController::class, 'show'])->name('ai-kit.api.conversations.show');

Route::get('usage', [UsageController::class, 'index'])->name('ai-kit.api.usage');
