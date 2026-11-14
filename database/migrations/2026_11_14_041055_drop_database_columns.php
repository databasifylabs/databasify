<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn(['engine_capabilities', 'operation', 'operation_started_at']);
        });

        Schema::dropIfExists('database_flows');
    }

    public function down(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->string('operation')->nullable();
            $table->timestamp('operation_started_at')->nullable();
        });

        Schema::create('database_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_id')->index();
            $table->foreignUuid('operation_node_id')->nullable();
            $table->foreignUuid('operation_log_id')->nullable();
            $table->string('flow');
            $table->unsignedSmallInteger('step')->default(0);
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->json('context');
            $table->string('operation_key', 128)->nullable();
            $table->string('state')->index();
            $table->text('error')->nullable();
            $table->string('phase')->nullable();
            $table->json('progress')->nullable();
            $table->timestamps();
        });
    }
};
