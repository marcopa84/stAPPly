<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        Http::fake(['backend.test/health' => Http::response(contract('health.200'))]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()->assertSee('In linea');
    }

    public function test_the_dashboard_lists_the_users_agents(): void
    {
        Http::fake(['backend.test/health' => Http::response(contract('health.200'))]);

        $user = User::factory()->create();
        Agent::factory()->for($user)->create(['name' => 'Assistente acquisti']);
        Agent::factory()->create(['name' => 'Agente di un altro']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('Assistente acquisti')
            ->assertDontSee('Agente di un altro');
    }

    public function test_the_dashboard_greets_with_the_mascot_and_links_the_latest_chats(): void
    {
        Http::fake(['backend.test/health' => Http::response(contract('health.200'))]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create(['name' => 'Assistente acquisti']);
        $conversation = Conversation::factory()->for($agent)->create(['title' => 'Ordini di ottobre']);
        Conversation::factory()->create(['title' => 'Chat di un altro']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-state="idle"', false)
            ->assertSee('Riprendi una chat')
            ->assertSee('Ordini di ottobre')
            ->assertSee(route('agents.chat', ['agent' => $agent, 'conversation' => $conversation]))
            ->assertDontSee('Chat di un altro');
    }

    public function test_without_chats_the_dashboard_explains_how_to_start_one(): void
    {
        Http::fake(['backend.test/health' => Http::response(contract('health.200'))]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertSee('Non hai ancora chat.');
    }

    public function test_the_dashboard_says_clearly_when_the_backend_is_down(): void
    {
        Http::fake(Http::failedConnection());

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Non raggiungibile')
            ->assertSee('Il backend non è raggiungibile o non ha risposto in tempo');
    }
}
