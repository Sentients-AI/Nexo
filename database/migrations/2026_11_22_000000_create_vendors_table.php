<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Vendors module's table (ADR-0005, ADR-0010). Its identity is
 * independent of users, so it has no foreign key to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            // Binary, NO PAD collation (MySQL 8.0.17+). Under the default
            // utf8mb4_0900_ai_ci the CHECK below would also accept 'PENDING'
            // or 'pendíng', and under utf8mb4_bin (PAD SPACE) 'pending ':
            // values outside the lifecycle.
            $table->string('status', 32)->collation('utf8mb4_0900_bin')->default('pending')->index();
            $table->timestamps(6);
        });

        // The schema builder has no CHECK constraint. The values are written
        // out rather than read from code, so this migration keeps creating
        // the same table if the lifecycle changes later; a change is a new
        // migration.
        DB::statement(
            "ALTER TABLE vendors ADD CONSTRAINT vendors_status_check CHECK (status IN ('pending', 'approved', 'suspended'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
