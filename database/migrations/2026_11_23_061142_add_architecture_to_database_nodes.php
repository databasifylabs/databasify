<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_nodes', function (Blueprint $table): void {
            $table->string('architecture')->nullable()->after('task_key');
        });
    }

    public function down(): void
    {
        Schema::table('database_nodes', function (Blueprint $table): void {
            $table->dropColumn('architecture');
        });
    }
};
