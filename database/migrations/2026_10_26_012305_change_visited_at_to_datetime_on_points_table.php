<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL `timestamp` only holds 1970-01-01 → 2038-01-19, so any visited_at outside that range
 * made the insert fail with a 500. `datetime` holds years 1000 → 9999. Values are always written
 * in the app timezone (UTC, see Point::visitedAt()), so the column needs no timezone conversion.
 *
 * The session timezone is deliberately left untouched: MySQL converts `timestamp` values through
 * it when altering the column, and existing values were written with that same default session
 * timezone, so they keep the exact wall-clock value whatever the server's timezone setting is.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('points', function (Blueprint $table) {
            $table->dateTime('visited_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Fails if a point has a visited_at outside the `timestamp` range: such rows must be fixed first.
     */
    public function down(): void
    {
        Schema::table('points', function (Blueprint $table) {
            $table->timestamp('visited_at')->nullable()->change();
        });
    }
};
