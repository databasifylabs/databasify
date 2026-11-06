<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('endpoint');
            $table->string('region', 40);
            $table->string('bucket', 63);
            $table->string('path_prefix', 200)->default('');
            $table->boolean('path_style')->default(false);
            $table->text('credentials');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }
};
