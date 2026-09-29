<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('patchbay.events.table', 'patchbay_events'), function (Blueprint $table) {
            $table->id();
            $table->ulid('app_id');
            $table->string('server')->nullable();
            // Whether the server sent it or received it, from the server's
            // point of view: 'sent' left the server, 'received' arrived at it.
            $table->string('direction', 8);
            $table->string('event');
            $table->string('channel')->nullable();
            // Truncated to patchbay.events.payload_length, and null when
            // recording payloads is turned off.
            $table->text('payload')->nullable();
            $table->timestamp('recorded_at');

            $table->index(['app_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('patchbay.events.table', 'patchbay_events'));
    }
};
