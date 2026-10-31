<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Di base un messaggio dell'utente; con assistant() una risposta senza tool.
 *
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => MessageRole::User,
            'content' => fake()->sentence().'?',
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::Assistant,
            'content' => fake()->paragraph(),
            'status' => 'ok',
            'route' => 'nessuna',
            'tools' => [],
            'sources' => [],
        ]);
    }
}
