<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id');
            $table->string('name');
            $table->string('type', 20);
            $table->text('credentials');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });
    }
};
