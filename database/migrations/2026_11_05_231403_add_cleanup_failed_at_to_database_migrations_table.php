<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_migrations', function (Blueprint $table) {
            $table->timestamp('cleanup_failed_at')->nullable()->after('finished_at');
        });
    }
};
