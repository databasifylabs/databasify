<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_project', function (Blueprint $table) {
            $table->string('token_id', 100);
            $table->uuid('project_id')->index();
            $table->primary(['token_id', 'project_id']);
        });
    }
};
