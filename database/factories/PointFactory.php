<?php

namespace Database\Factories;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use App\Models\Point;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Point>
 */
class PointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'title' => fake()->words(3, true),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'note' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(PointStatus::cases()),
            'visited_at' => null,
            'visibility' => PointVisibility::Private,
        ];
    }
}
