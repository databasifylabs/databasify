<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_id');
            $table->string('name', 63);
            $table->string('schema', 63);
            $table->string('mode', 16);
            $table->unsignedSmallInteger('size');
            $table->timestamps();
            $table->unique(['database_id', 'name']);
        });
    }
};
