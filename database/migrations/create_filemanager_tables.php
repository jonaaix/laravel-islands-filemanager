<?php

declare(strict_types=1);

use Aaix\LaravelIslandsFilemanager\FileManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $users = (new (FileManager::userModel()))->getTable();

        Schema::create(FileManager::table('folders'), function (Blueprint $table) use ($users): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained(FileManager::table('folders'))->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('created_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();
        });

        Schema::create(FileManager::table('files'), function (Blueprint $table) use ($users): void {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained(FileManager::table('folders'))->cascadeOnDelete();
            $table->string('name');
            $table->string('path', 500)->nullable();
            $table->string('thumbnail_path', 500)->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();
        });

        Schema::create(FileManager::table('comments'), function (Blueprint $table) use ($users): void {
            $table->id();
            $table->foreignId('file_id')->constrained(FileManager::table('files'))->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained($users)->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create(FileManager::table('file_contents'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')->unique()->constrained(FileManager::table('files'))->cascadeOnDelete();
            $table->longText('text');
            $table->timestamps();
        });

        Schema::create(FileManager::table('view_settings'), function (Blueprint $table) use ($users): void {
            $table->id();
            $table->foreignId('user_id')->constrained($users)->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained(FileManager::table('folders'))->cascadeOnDelete();
            $table->string('mode', 10);
            $table->unsignedSmallInteger('tile_size');
            $table->string('sort_by', 10)->default('name');
            $table->string('sort_direction', 4)->default('asc');
            $table->timestamps();
            $table->index(['user_id', 'folder_id']);
        });

        Schema::create(FileManager::table('preferences'), function (Blueprint $table) use ($users): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained($users)->cascadeOnDelete();
            $table->unsignedSmallInteger('info_width')->nullable();
            $table->unsignedSmallInteger('tree_width')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['preferences', 'view_settings', 'file_contents', 'comments', 'files', 'folders'] as $name) {
            Schema::dropIfExists(FileManager::table($name));
        }
    }
};
