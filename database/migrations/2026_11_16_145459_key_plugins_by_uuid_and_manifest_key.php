<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropColumn('engines');
        });

        Schema::table('plugins', function (Blueprint $table) {
            $table->uuid('id')->change();
            $table->string('key', 81)->unique()->after('id');
            $table->string('engine', 40)->nullable()->after('autoload');
        });
    }
};
