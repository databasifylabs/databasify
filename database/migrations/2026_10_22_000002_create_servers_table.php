<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->index();
            $table->foreignUuid('network_id')->nullable();
            $table->foreignUuid('server_provider_id')->nullable()->index();
            $table->string('name')->unique();
            $table->string('public_ip')->nullable()->unique();
            $table->string('private_ip')->nullable();
            $table->string('provider_server_id')->nullable();
            $table->string('region')->nullable();
            $table->string('plan')->nullable();
            $table->string('image')->nullable();
            $table->string('wireguard_public_key')->nullable();
            $table->string('network_status', 16)->nullable();
            $table->string('network_issue')->nullable();
            $table->timestamp('network_checked_at')->nullable();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            $table->text('ssh_password')->nullable();
            $table->string('host_key_fingerprint')->nullable();
            $table->string('status')->default('pending');
            $table->string('os')->nullable();
            $table->unsignedSmallInteger('cpus')->nullable();
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('disk_gb')->nullable();
            $table->json('interface_subnets')->nullable();
            $table->timestamps();
            $table->unique(['network_id', 'private_ip']);
        });

        Schema::create('server_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('server_id');
            $table->string('username');
            $table->timestamps();
            $table->unique(['server_id', 'username']);
        });
    }
};
