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
        Schema::table(FileManager::table('preferences'), function (Blueprint $table): void {
            $table->json('tabs')->nullable()->after('tree_width');
            $table->unsignedSmallInteger('active_tab')->default(0)->after('tabs');
        });
    }

    public function down(): void
    {
        Schema::table(FileManager::table('preferences'), function (Blueprint $table): void {
            $table->dropColumn(['tabs', 'active_tab']);
        });
    }
};
