<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'name']);
        });

        Schema::create('project_source_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_platform');
            $table->string('identifier_type');
            $table->string('source_identifier', 512);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'source_platform', 'identifier_type', 'source_identifier'],
                'project_source_identifiers_unique',
            );
            $table->index(['project_id', 'source_platform']);
        });

        Schema::create('conversation_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'project_id']);
            $table->index(['user_id', 'project_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_project');
        Schema::dropIfExists('project_source_identifiers');
        Schema::dropIfExists('projects');
    }
};
