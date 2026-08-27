<?php

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->text('note')->nullable();
            $table->enum('status', array_column(PointStatus::cases(), 'value'))
                ->default(PointStatus::ATester->value);
            $table->timestamp('visited_at')->nullable();
            $table->enum('visibility', array_column(PointVisibility::cases(), 'value'))
                ->default(PointVisibility::Private->value);
            $table->timestamps();

            $table->index(['owner_id', 'latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('points');
    }
};
