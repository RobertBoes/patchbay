<?php

namespace RobertBoes\Patchbay\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RobertBoes\Patchbay\Models\App;

class AppFactory extends Factory
{
    protected $model = App::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'allowed_origins' => ['*'],
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn() => ['active' => false]);
    }
}
