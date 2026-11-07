<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_id');
            $table->string('name');
            $table->text('password');
            $table->string('password_encryption')->nullable();
            $table->timestamps();
            $table->unique(['database_id', 'name']);
        });

        Schema::create('schemas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_id');
            $table->string('name');
            $table->timestamps();
            $table->unique(['database_id', 'name']);
        });

        Schema::create('trusted_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('database_id');
            $table->string('cidr');
            $table->string('label')->nullable();
            $table->timestamps();
            $table->unique(['database_id', 'cidr']);
        });
    }
};
