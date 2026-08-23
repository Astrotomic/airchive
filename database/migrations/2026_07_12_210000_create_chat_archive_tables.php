<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_platform');
            $table->string('source_conversation_id');
            $table->string('title')->nullable();
            $table->unsignedBigInteger('canonical_leaf_message_id')->nullable();
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata');
            $table->timestamps();

            $table->unique(['user_id', 'source_platform', 'source_conversation_id']);
            $table->index(['user_id', 'last_message_at']);

            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->fullText('title');
            }
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('source_message_id');
            $table->string('role');
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->boolean('is_on_canonical_path')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->json('metadata');
            $table->timestamp('updated_at')->nullable();

            $table->index(['conversation_id', 'source_message_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('canonical_leaf_message_id')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
        });

        Schema::create('content_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('block_type');
            $table->text('text_content')->nullable();
            $table->json('structured_content')->nullable();
            $table->string('language')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->fullText('text_content');
            }
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('content_block_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_platform')->nullable();
            $table->string('source_attachment_id')->nullable();
            $table->string('attachment_type');
            $table->string('filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('external_url')->nullable();
            $table->json('source_ref')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'attachment_type', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
            $table->index(['user_id', 'source_platform', 'source_attachment_id'], 'attachments_source_lookup_index');
            $table->index(['user_id', 'checksum']);
        });

        Schema::create('conversation_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->timestamp('imported_at');
            $table->string('source_file');
            $table->string('source_format');
            $table->string('raw_checksum');
            $table->string('raw_storage_path');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('file_path');
            $table->string('detected_format')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('conversation_sources');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('content_blocks');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
