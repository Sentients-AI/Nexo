<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32)->collation('utf8mb4_0900_bin');
            $table->string('status', 32)->collation('utf8mb4_0900_bin')->default('active');
            $table->timestamp('deactivated_at', 6)->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->storedAs("IF(`status` = 'active', 1, NULL)");
            $table->unsignedTinyInteger('owner_marker')->nullable()->storedAs("IF(`status` = 'active' AND `role` = 'owner', 1, NULL)");
            $table->timestamps(6);
            $table->unique(['user_id', 'active_marker'], 'vendor_members_one_active_per_user');
            $table->unique(['vendor_id', 'owner_marker'], 'vendor_members_one_active_owner');
            $table->index(['vendor_id', 'status'], 'vendor_members_vendor_status_index');
            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE vendor_members
                ADD CONSTRAINT vendor_members_role_check CHECK (role IN ('owner', 'manager', 'fulfilment', 'finance', 'support')),
                ADD CONSTRAINT vendor_members_status_check CHECK (status IN ('active', 'deactivated')),
                ADD CONSTRAINT vendor_members_deactivation_check CHECK ((status = 'deactivated') = (deactivated_at IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_members');
    }
};
