<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Il backend_id non si imposta qui: lo genera il modello alla creazione.
 *
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = fake()->company();

        return [
            'user_id' => User::factory(),
            'name' => "Assistente {$company}",
            'description' => fake()->sentence(),
            'instructions' => "Sei l'assistente di {$company}. Rispondi in italiano, in poche frasi.",
            'is_demo' => false,
        ];
    }

    /**
     * Un agente demo: i suoi documenti e le sue tabelle li carica lo script del backend.
     */
    public function demo(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_demo' => true,
        ]);
    }
}
