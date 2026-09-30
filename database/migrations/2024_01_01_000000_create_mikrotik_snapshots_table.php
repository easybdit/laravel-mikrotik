<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('connection');
            $table->timestamp('captured_at');
            $table->json('resource')->nullable();
            $table->json('health')->nullable();
            $table->json('interfaces')->nullable();
            $table->timestamps();

            $table->index(['connection', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_snapshots');
    }
};
