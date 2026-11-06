<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name');
            $table->string('version', 40);
            $table->string('source');
            $table->string('class');
            $table->json('autoload');
            $table->string('requires', 100)->nullable();
            $table->string('icon')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }
};
