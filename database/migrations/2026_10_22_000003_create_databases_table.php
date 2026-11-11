<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->index();
            $table->foreignUuid('dns_zone_id')->nullable()->index();
            $table->string('name')->unique();
            $table->string('engine');
            $table->string('version_line', 20);
            $table->string('mode');
            $table->string('status')->default('creating');
            $table->json('image');
            $table->json('available_image')->nullable();
            $table->timestamp('retune_needed_at')->nullable();
            $table->unsignedTinyInteger('maintenance_day')->default(0);
            $table->unsignedTinyInteger('maintenance_hour')->default(3);
            $table->text('settings');
            $table->string('operation')->nullable();
            $table->timestamp('operation_started_at')->nullable();
            $table->json('engine_capabilities')->nullable();
            $table->json('config_fields')->nullable();
            $table->json('log_units')->nullable();
            $table->timestamps();
        });

        Schema::create('database_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_id');
            $table->foreignUuid('server_id')->index();
            $table->string('name', 43)->nullable()->unique();
            $table->unsignedSmallInteger('position');
            $table->string('kind')->default('member');
            $table->string('role')->default('unknown');
            $table->string('engine_version', 40)->nullable();
            $table->string('certificate_fingerprint', 64)->nullable();
            $table->unsignedTinyInteger('missed_status_reads')->default(0);
            $table->timestamps();
            $table->unique(['database_id', 'server_id']);
        });
    }
};
