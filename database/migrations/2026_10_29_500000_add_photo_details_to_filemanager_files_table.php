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
        Schema::table(FileManager::table('files'), function (Blueprint $table): void {
            $table->string('taken_at', 32)->nullable()->after('height');
            $table->string('camera')->nullable()->after('taken_at');
        });
    }

    public function down(): void
    {
        Schema::table(FileManager::table('files'), function (Blueprint $table): void {
            $table->dropColumn(['taken_at', 'camera']);
        });
    }
};
