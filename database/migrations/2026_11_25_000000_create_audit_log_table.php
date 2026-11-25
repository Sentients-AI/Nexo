<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Shared: the append-only audit log (ADR-0023 Decision 3; INV-19; #34).
 * Append-only is an application convention enforced by L10, not by the
 * database (OD-8). There is no updated_at, and the actor ids have no foreign
 * key (OD-7): Shared depends on no module's schema, and users are
 * anonymised, not deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at', 6);
            // Binary, NO PAD collation, as for vendors.status: under the
            // default collation the CHECK below would also accept 'User',
            // 'usér' or 'user '.
            $table->string('actor_type', 16)->collation('utf8mb4_0900_bin');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('impersonator_user_id')->nullable();
            $table->string('actor_label', 191)->nullable();
            $table->string('action', 100)->collation('utf8mb4_0900_bin');
            $table->string('subject_type', 64)->collation('utf8mb4_0900_bin');
            $table->unsignedBigInteger('subject_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();

            $table->index(['subject_type', 'subject_id'], 'audit_log_subject_index');
            $table->index(['actor_user_id', 'occurred_at'], 'audit_log_actor_index');
        });

        // Written out rather than read from ActorType, so this migration keeps
        // creating the same constraints if the enum changes later; a change
        // is a new migration.
        DB::statement(
            "ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_type_check CHECK (actor_type IN ('user', 'system', 'console'))"
        );
        DB::statement(
            "ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_user_check CHECK ((actor_type = 'user') = (actor_user_id IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE audit_log ADD CONSTRAINT audit_log_impersonator_check CHECK (impersonator_user_id IS NULL OR actor_type = 'user')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
