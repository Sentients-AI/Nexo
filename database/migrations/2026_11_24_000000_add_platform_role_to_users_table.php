<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: a user's platform staff role (ADR-0011 Decision 3; #33). The
 * column is additive and nullable, so every existing user stays a non-staff
 * user (NULL); there is no default role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Binary, NO PAD collation, as for vendors.status: under the
            // default collation the CHECK below would also accept 'Admin',
            // 'àdmin' or 'admin '.
            $table->string('platform_role', 32)->collation('utf8mb4_0900_bin')->nullable();
        });

        // Written out rather than read from PlatformRole, so this migration
        // keeps creating the same constraint if the enum changes later; a
        // change is a new migration.
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_platform_role_check CHECK (platform_role IS NULL OR platform_role IN ('admin', 'operations', 'finance', 'support'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK users_platform_role_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('platform_role');
        });
    }
};
