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

        foreach (['folders', 'files'] as $name) {
            Schema::table(FileManager::table($name), function (Blueprint $table) use ($users): void {
                $table->softDeletes()->index();
                $table->boolean('trash_root')->default(false);
                $table->foreignId('deleted_by_id')->nullable()->constrained($users)->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['files', 'folders'] as $name) {
            Schema::table(FileManager::table($name), function (Blueprint $table): void {
                $table->dropConstrainedForeignId('deleted_by_id');
                $table->dropColumn('trash_root');
                $table->dropSoftDeletes();
            });
        }
    }
};
