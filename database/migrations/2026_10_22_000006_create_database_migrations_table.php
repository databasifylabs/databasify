<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_migrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('database_node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode');
            $table->string('status');
            $table->text('source');
            $table->json('position')->nullable();
            $table->text('detail')->nullable();
            $table->unsignedInteger('lag_seconds')->nullable();
            $table->unsignedSmallInteger('unhealthy_checks')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }
};
