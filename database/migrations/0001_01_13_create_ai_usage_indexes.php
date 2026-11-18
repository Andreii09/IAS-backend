<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI chat agent owns the chat_messages table migration. This migration
     * only adds reporting indexes once that table exists; it is a no-op
     * otherwise so migration ordering stays safe.
     */
    public function up(): void
    {
        if (! Schema::hasTable('chat_messages')) {
            return;
        }

        $existing = [];

        try {
            $existing = array_column(Schema::getIndexes('chat_messages'), 'name');
        } catch (Throwable) {
            $existing = [];
        }

        Schema::table('chat_messages', function (Blueprint $table) use ($existing): void {
            if (! in_array('chat_messages_user_id_created_at_index', $existing, true)) {
                $table->index(['user_id', 'created_at']);
            }

            if (! in_array('chat_messages_session_id_index', $existing, true)) {
                $table->index('session_id');
            }

            if (! in_array('chat_messages_flagged_created_at_index', $existing, true)) {
                $table->index(['flagged', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('chat_messages')) {
            return;
        }

        $existing = [];

        try {
            $existing = array_column(Schema::getIndexes('chat_messages'), 'name');
        } catch (Throwable) {
            $existing = [];
        }

        Schema::table('chat_messages', function (Blueprint $table) use ($existing): void {
            if (in_array('chat_messages_user_id_created_at_index', $existing, true)) {
                $table->dropIndex(['user_id', 'created_at']);
            }

            if (in_array('chat_messages_session_id_index', $existing, true)) {
                $table->dropIndex(['session_id']);
            }

            if (in_array('chat_messages_flagged_created_at_index', $existing, true)) {
                $table->dropIndex(['flagged', 'created_at']);
            }
        });
    }
};
