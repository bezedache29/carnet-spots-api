<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds what the mobile sync needs on points and tags: a client-generated `uuid` (so replaying an
     * offline creation never makes a duplicate), soft deletes (so deletions reach the phone as
     * tombstones) and an `(owner_id, updated_at)` index for the "changed since" query.
     */
    public function up(): void
    {
        foreach (['points', 'tags'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->after('id');
                $table->softDeletes();
            });

            DB::table($tableName)->whereNull('uuid')->lazyById()->each(
                fn (object $row) => DB::table($tableName)->where('id', $row->id)->update(['uuid' => (string) Str::uuid7()])
            );

            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('uuid')->nullable(false)->change();
                $table->unique('uuid');
                $table->index(['owner_id', 'updated_at']);
            });
        }

        // A soft-deleted tag keeps its row, so `unique(owner_id, name)` would forbid creating a new tag
        // with the name of a deleted one. `active_name` is NULL once the tag is deleted, and MySQL allows
        // any number of NULLs in a unique index: only live tags compete for a name.
        Schema::table('tags', function (Blueprint $table) {
            $table->string('active_name')->nullable()->storedAs('if(deleted_at is null, name, null)');
            // Added before dropping the old index: the owner_id foreign key needs an index starting with owner_id.
            $table->unique(['owner_id', 'active_name']);
            $table->dropUnique(['owner_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Soft-deleted rows are deleted for good first (their point_tag rows go with them through the
     * foreign keys): once `deleted_at` is gone they would come back as live rows, and a deleted tag
     * sharing its name with a live one would make the old `unique(owner_id, name)` index fail.
     */
    public function down(): void
    {
        DB::table('points')->whereNotNull('deleted_at')->delete();
        DB::table('tags')->whereNotNull('deleted_at')->delete();

        Schema::table('tags', function (Blueprint $table) {
            $table->unique(['owner_id', 'name']);
            $table->dropUnique(['owner_id', 'active_name']);
            $table->dropColumn('active_name');
        });

        foreach (['points', 'tags'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['owner_id', 'updated_at']);
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
                $table->dropSoftDeletes();
            });
        }
    }
};
