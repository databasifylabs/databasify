<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_in_time_recoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('storage_provider_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('retention_days');
            $table->unsignedTinyInteger('backup_hour');
            $table->string('health')->default('pending');
            $table->unsignedInteger('backups_count')->default(0);
            $table->timestamp('earliest_restorable_at')->nullable();
            $table->timestamp('latest_restorable_at')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->text('problem')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }
};
