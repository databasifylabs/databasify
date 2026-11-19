<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_backup_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->index();
            $table->uuid('database_id')->index();
            $table->string('name');
            $table->string('protocol', 8);
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('username');
            $table->string('path');
            $table->text('host_key')->nullable();
            $table->text('credentials');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('backup_hour')->default(3);
            $table->timestamp('next_backup_at')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('file_backup_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('file_backup_destination_id')->index();
            $table->string('status', 16)->default('queued');
            $table->string('remote_file')->nullable();
            $table->text('problem')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_backup_runs');
        Schema::dropIfExists('file_backup_destinations');
    }
};
