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
            // No ->constrained() here: mikrotik_incidents is created in a
            // later migration and references this table (its
            // first_alert_id), so the FK constraint for this column is
            // added there instead, once mikrotik_incidents exists.
            $table->foreignId('incident_id')->nullable();
            $table->string('connection');
            $table->string('metric');
            $table->string('operator', 2);
            $table->double('threshold');
            $table->double('value');
            // 'triggered' while the condition that raised this alert is
            // still considered ongoing; 'resolved' once a later evaluation
            // of the same rule no longer matches. RuleEvaluator uses this
            // to avoid creating a duplicate 'triggered' row for a rule
            // that is already active — see RuleEvaluator::evaluate().
            $table->string('status')->default('triggered');
            // Mirrors $rule_id only while $status is 'triggered', cleared
            // to null on resolution -- the same pattern (and the same
            // reason) as mikrotik_incidents' open_rule_id: the unique
            // index below (P11) is what makes "at most one active alert
            // per rule+connection" a real database-level guarantee, not
            // just the application-level check-then-create RuleEvaluator
            // already did. Under two concurrent `mikrotik:monitor`
            // processes racing to evaluate the same rule at nearly the
            // same time, both could see "no active alert yet" before
            // either commits -- without this, both would insert a
            // 'triggered' row. Any number of *resolved* alerts for the
            // same rule+connection still coexist freely (MySQL, SQLite,
            // and PostgreSQL all treat multiple NULLs as non-conflicting
            // in a unique index).
            $table->unsignedBigInteger('active_rule_id')->nullable();
            $table->string('message')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['connection', 'triggered_at']);
            $table->index(['rule_id', 'status']);
            $table->unique(['active_rule_id', 'connection'], 'mikrotik_alerts_one_active_per_rule_connection');
            // ->constrained() relies on the database engine to index a
            // foreign key column automatically -- true for MySQL/InnoDB,
            // NOT true for SQLite or PostgreSQL. snapshot_id has no
            // covering composite index (unlike rule_id, covered by the
            // index above), and incident_id has no ->constrained() call
            // at all (see its own comment), so both get an explicit
            // index for parity across engines.
            $table->index('snapshot_id');
            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_alerts');
    }
};
