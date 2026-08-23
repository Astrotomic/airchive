<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('first_message_at')->nullable()->after('canonical_leaf_message_id');
            $table->timestamp('last_message_at')->nullable()->after('first_message_at');
            $table->index(['user_id', 'last_message_at']);
        });

        DB::table('conversations')->update([
            'first_message_at' => DB::raw('(SELECT MIN(messages.created_at) FROM messages WHERE messages.conversation_id = conversations.id)'),
            'last_message_at' => DB::raw('(SELECT MAX(messages.created_at) FROM messages WHERE messages.conversation_id = conversations.id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'last_message_at']);
            $table->dropColumn(['first_message_at', 'last_message_at']);
        });
    }
};
