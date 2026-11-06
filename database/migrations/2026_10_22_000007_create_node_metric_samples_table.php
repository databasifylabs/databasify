<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_metric_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_node_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->decimal('cpu_percent', 5, 1)->nullable();
            $table->decimal('load_1m', 8, 2)->nullable();
            $table->decimal('memory_percent', 5, 1)->nullable();
            $table->decimal('disk_percent', 5, 1)->nullable();
            $table->unsignedInteger('connections')->nullable();
            $table->decimal('ops_per_second', 12, 2)->nullable();
            $table->unsignedBigInteger('query_total')->nullable();
            $table->index(['database_node_id', 'sampled_at']);
        });
    }
};
