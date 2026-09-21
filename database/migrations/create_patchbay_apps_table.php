<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('patchbay.table', 'patchbay_apps'), function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');

            $table->string('key')->unique();
            $table->text('secret');

            $table->json('allowed_origins')->nullable();
            $table->unsignedInteger('ping_interval')->nullable();
            $table->unsignedInteger('activity_timeout')->nullable();
            $table->unsignedInteger('max_message_size')->nullable();
            $table->unsignedInteger('max_connections')->nullable();
            $table->string('accept_client_events_from')->nullable();
            $table->json('rate_limiting')->nullable();

            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('patchbay.table', 'patchbay_apps'));
    }
};
