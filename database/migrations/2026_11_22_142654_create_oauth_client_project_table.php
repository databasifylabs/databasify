<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_client_project', function (Blueprint $table) {
            $table->uuid('client_id');
            $table->uuid('project_id')->index();
            $table->primary(['client_id', 'project_id']);
        });
    }
};
