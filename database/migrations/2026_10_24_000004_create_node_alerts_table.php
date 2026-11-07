<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_node_id');
            $table->string('metric', 32);
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->index(['database_node_id', 'resolved_at']);
        });
    }
};
