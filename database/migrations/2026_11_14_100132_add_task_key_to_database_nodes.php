<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_nodes', function (Blueprint $table) {
            $table->string('task_key')->nullable()->after('server_id');
        });
    }

    public function down(): void
    {
        Schema::table('database_nodes', function (Blueprint $table) {
            $table->dropColumn('task_key');
        });
    }
};
