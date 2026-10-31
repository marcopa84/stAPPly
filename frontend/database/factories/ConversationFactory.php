<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Il session_id non si imposta qui: lo genera il modello alla creazione.
 *
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            // La conversazione è di chi possiede l'agente, come quelle create dalla chat.
            'user_id' => fn (array $attributes) => Agent::query()->whereKey($attributes['agent_id'])->value('user_id'),
            'title' => rtrim(fake()->unique()->sentence(3), '.'),
        ];
    }
}
