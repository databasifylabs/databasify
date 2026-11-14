<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_logs', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropColumn(['group_id', 'group_name']);
        });
    }

    public function down(): void
    {
        Schema::table('server_logs', function (Blueprint $table) {
            $table->uuid('group_id')->nullable()->index();
            $table->string('group_name')->nullable();
        });
    }
};
