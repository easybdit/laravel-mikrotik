<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_rules', function (Blueprint $table) {
            $table->id();
            // Null = applies to every connection; otherwise a specific
            // named connection (config('mikrotik.connections.<name>')).
            $table->string('connection')->nullable();
            $table->string('name');
            // Dot path into a MikrotikSnapshot's stored data, e.g.
            // "resource.cpu_load", "health.cpu-temperature.value",
            // "interfaces.ether1.rx_error". See MikrotikRule::OPERATORS
            // for the supported $operator values.
            $table->string('metric');
            $table->string('operator', 2);
            $table->double('threshold');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['connection', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_rules');
    }
};
