<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_alerts', function (Blueprint $table) {
            $table->id();
            // nullOnDelete (not cascade): an alert is a historical record
            // of what fired; it must survive its rule or snapshot being
            // deleted later (e.g. snapshot retention pruning), which is
            // why $connection/$metric/$operator/$threshold/$value below
            // are also stored directly rather than only reachable via
            // these relations.
            $table->foreignId('rule_id')->nullable()->constrained('mikrotik_rules')->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('mikrotik_snapshots')->nullOnDelete();
            $table->string('connection');
            $table->string('metric');
            $table->string('operator', 2);
            $table->double('threshold');
            $table->double('value');
            $table->string('message')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->index(['connection', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_alerts');
    }
};
