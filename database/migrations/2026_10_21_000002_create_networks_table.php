<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->index();
            $table->foreignUuid('server_provider_id')->nullable();
            $table->string('name');
            $table->string('cidr', 43);
            $table->string('kind', 16)->default('custom');
            $table->unsignedSmallInteger('listen_port')->nullable();
            $table->string('external_id')->nullable();
            $table->string('region')->nullable();
            $table->timestamps();
            $table->unique(['server_provider_id', 'external_id']);
        });
    }
};
