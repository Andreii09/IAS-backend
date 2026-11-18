<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->string('category', 50)->nullable();
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('cover_image', 2048)->nullable();
            $table->json('tags')->nullable();
            $table->string('status', 20)->nullable();
            $table->string('visibility', 20)->nullable();
            $table->timestamp('published_at')->nullable();

            $table->longText('snapshot')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['news_id', 'id']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_revisions');
    }
};
