<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table): void {
            $table->dropColumn('engine_capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('databases', function (Blueprint $table): void {
            $table->json('engine_capabilities')->nullable();
        });
    }
};
