<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->nullable()->constrained('mikrotik_rules')->nullOnDelete();
            $table->foreignId('first_alert_id')->nullable()->constrained('mikrotik_alerts')->nullOnDelete();
            $table->string('connection');
            $table->string('metric');
            // 'open' while the underlying alert episode is still
            // considered ongoing; 'resolved' once it clears. See
            // Monitoring\IncidentManager.
            $table->string('status')->default('open');
            // Mirrors $rule_id only while $status is 'open', cleared to
            // null on resolution. MySQL/SQLite/PostgreSQL all treat
            // multiple NULLs as non-conflicting in a unique index, so any
            // number of *resolved* incidents for the same rule+connection
            // coexist freely, while the unique index below makes "at most
            // one open incident per rule+connection" a real
            // database-level guarantee -- safe even under two concurrent
            // `mikrotik:monitor` processes racing to open the same one.
            $table->unsignedBigInteger('open_rule_id')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['connection', 'status']);
            // rule_id has no covering composite index here (unlike
            // mikrotik_alerts, where ['rule_id','status'] already covers
            // it) -- MonitoringAnalytics::incidentCountsByRule() groups by
            // it directly, and IncidentManager looks up "the open
            // incident for this rule+connection" by it on every
            // resolution. ->constrained() alone only guarantees an index
            // on MySQL/InnoDB, not SQLite/PostgreSQL -- see the same note
            // on mikrotik_alerts' migration.
            $table->index('rule_id');
            $table->unique(['open_rule_id', 'connection'], 'mikrotik_incidents_one_open_per_rule_connection');
        });

        // mikrotik_alerts.incident_id was added without a constraint in
        // its own (earlier) migration, since this table didn't exist yet
        // at that point -- the constraint is completed here instead.
        Schema::table('mikrotik_alerts', function (Blueprint $table) {
            $table->foreign('incident_id')->references('id')->on('mikrotik_incidents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_alerts', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);
        });

        Schema::dropIfExists('mikrotik_incidents');
    }
};
