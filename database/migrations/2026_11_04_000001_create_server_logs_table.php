<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('database_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('running');
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->uuid('group_id')->nullable()->index();
            $table->string('group_name')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'created_at']);
            $table->index(['database_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }
};
