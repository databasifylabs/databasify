<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('type', 32);
            $table->text('credentials');
            $table->timestamps();
        });

        Schema::create('dns_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dns_provider_id')->constrained()->cascadeOnDelete();
            $table->string('name', 253)->unique();
            $table->string('external_id');
            $table->text('certificate')->nullable();
            $table->text('certificate_key')->nullable();
            $table->timestamps();
        });
    }
};
