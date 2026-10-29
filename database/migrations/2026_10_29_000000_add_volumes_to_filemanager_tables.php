<?php

declare(strict_types=1);

use Aaix\LaravelIslandsFilemanager\FileManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = ['folders', 'files', 'view_settings'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table(FileManager::table($name), function (Blueprint $table): void {
                $table->string('volume', 64)->default(FileManager::defaultVolume())->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table(FileManager::table($name), function (Blueprint $table): void {
                $table->dropIndex(['volume']);
                $table->dropColumn('volume');
            });
        }
    }
};
