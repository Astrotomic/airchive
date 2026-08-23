<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_platform')->nullable()->after('content_block_id');
            $table->string('source_attachment_id')->nullable()->after('source_platform');
            $table->string('checksum', 64)->nullable()->after('byte_size');

            $table->index(['user_id', 'attachment_type', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
            $table->index(['user_id', 'source_platform', 'source_attachment_id'], 'attachments_source_lookup_index');
            $table->index(['user_id', 'checksum']);
        });

        DB::statement(<<<'SQL'
            UPDATE attachments
            SET conversation_id = (
                SELECT messages.conversation_id
                FROM messages
                WHERE messages.id = attachments.message_id
            )
            WHERE message_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE attachments
            SET user_id = (
                SELECT conversations.user_id
                FROM messages
                INNER JOIN conversations ON conversations.id = messages.conversation_id
                WHERE messages.id = attachments.message_id
            )
            WHERE message_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE attachments
            SET source_platform = (
                SELECT conversations.source_platform
                FROM messages
                INNER JOIN conversations ON conversations.id = messages.conversation_id
                WHERE messages.id = attachments.message_id
            )
            WHERE message_id IS NOT NULL
        SQL);

        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreignId('message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'attachment_type', 'created_at']);
            $table->dropIndex(['conversation_id', 'created_at']);
            $table->dropIndex('attachments_source_lookup_index');
            $table->dropIndex(['user_id', 'checksum']);

            $table->dropConstrainedForeignId('conversation_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['source_platform', 'source_attachment_id', 'checksum']);
        });
    }
};
