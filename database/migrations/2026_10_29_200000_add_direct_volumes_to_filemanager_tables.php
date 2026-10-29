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
        Schema::table(FileManager::table('folders'), function (Blueprint $table): void {
            $table->string('disk_path', 1000)->nullable()->after('name');
        });

        Schema::table(FileManager::table('files'), function (Blueprint $table): void {
            $table->string('fingerprint', 191)->nullable()->after('size')->index();
        });
    }

    public function down(): void
    {
        Schema::table(FileManager::table('files'), function (Blueprint $table): void {
            $table->dropIndex(['fingerprint']);
            $table->dropColumn('fingerprint');
        });

        Schema::table(FileManager::table('folders'), function (Blueprint $table): void {
            $table->dropColumn('disk_path');
        });
    }
};
