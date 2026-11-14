<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_nodes', function (Blueprint $table) {
            $table->string('status')->nullable();
            $table->json('config')->nullable();
        });

        Schema::table('trusted_sources', function (Blueprint $table) {
            $table->foreignUuid('database_node_id')->nullable()->after('database_id');
            $table->dropUnique(['database_id', 'cidr']);
            $table->unique(['database_id', 'database_node_id', 'cidr']);
        });

        Schema::table('connection_pools', function (Blueprint $table) {
            $table->foreignUuid('database_node_id')->nullable()->after('database_id');
            $table->dropUnique(['database_id', 'name']);
            $table->unique(['database_id', 'database_node_id', 'name']);
        });

        DB::table('database_nodes')->where('kind', 'read_only')->orderBy('id')->each(function (object $node): void {
            $settings = DB::table('databases')->where('id', $node->database_id)->value('settings');
            $decoded = is_string($settings) ? json_decode(Crypt::decryptString($settings), true) : [];
            $config = is_array($decoded) && is_array($decoded['config'] ?? null) ? $decoded['config'] : [];

            DB::table('database_nodes')->where('id', $node->id)->update(['status' => 'ready', 'config' => json_encode((object) $config)]);

            foreach (['trusted_sources', 'connection_pools'] as $table) {
                DB::table($table)->where('database_id', $node->database_id)->whereNull('database_node_id')->orderBy('id')->get()
                    ->each(fn (object $row) => DB::table($table)->insert([...(array) $row, 'id' => (string) Str::uuid(), 'database_node_id' => $node->id]));
            }
        });
    }

    public function down(): void
    {
        DB::table('trusted_sources')->whereNotNull('database_node_id')->delete();
        DB::table('connection_pools')->whereNotNull('database_node_id')->delete();

        Schema::table('connection_pools', function (Blueprint $table) {
            $table->dropUnique(['database_id', 'database_node_id', 'name']);
            $table->unique(['database_id', 'name']);
            $table->dropColumn('database_node_id');
        });

        Schema::table('trusted_sources', function (Blueprint $table) {
            $table->dropUnique(['database_id', 'database_node_id', 'cidr']);
            $table->unique(['database_id', 'cidr']);
            $table->dropColumn('database_node_id');
        });

        Schema::table('database_nodes', function (Blueprint $table) {
            $table->dropColumn(['status', 'config']);
        });
    }
};
