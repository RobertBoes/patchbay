<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('patchbay.metrics.table', 'patchbay_metrics'), function (Blueprint $table) {
            $table->id();
            $table->ulid('app_id');
            $table->string('server')->nullable();
            $table->unsignedInteger('connections')->default(0);
            $table->unsignedInteger('channels')->default(0);
            $table->unsignedInteger('messages_sent')->default(0);
            $table->unsignedInteger('messages_received')->default(0);
            $table->timestamp('recorded_at');

            $table->index(['app_id', 'recorded_at']);
            $table->index(['server', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('patchbay.metrics.table', 'patchbay_metrics'));
    }
};
