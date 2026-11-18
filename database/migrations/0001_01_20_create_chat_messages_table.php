<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('session_id', 64);
            $table->string('role', 16);
            $table->text('content');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('model')->nullable();
            $table->boolean('flagged')->default(false);
            $table->boolean('failed')->nullable()->default(false);
            $table->timestamp('created_at');

            $table->index('user_id', 'chat_messages_user_id_index');
            $table->index('session_id', 'chat_messages_session_id_index');
            $table->index('created_at', 'chat_messages_created_at_index');
            $table->index('flagged', 'chat_messages_flagged_index');
            $table->index(
                ['user_id', 'session_id', 'created_at'],
                'chat_messages_user_session_created_index'
            );
        });

        // Added separately so this migration does not depend on the exact file
        // name/order of the users-table migration.
        if (Schema::hasTable('users')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->foreign('user_id', 'chat_messages_user_id_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
