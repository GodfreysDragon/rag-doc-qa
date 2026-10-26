<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_refused_by_the_api(): void
    {
        $this->getJson(route('api.documents.index'))->assertUnauthorized();
        $this->getJson(route('api.user'))->assertUnauthorized();

        // The chat stream asks for text/event-stream, not JSON; it must still get a 401, not a redirect.
        $this->post(route('api.chat'), ['question' => 'Hi'], ['Accept' => 'text/event-stream'])->assertUnauthorized();
    }

    public function test_a_visitor_can_register_and_is_signed_in(): void
    {
        $this->postJson(route('api.register'), [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com');

        $this->assertAuthenticatedAs(User::sole());
    }

    public function test_registration_can_be_switched_off(): void
    {
        config(['rag.auth.registration' => false]);

        $this->postJson(route('api.register'), [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertForbidden();

        $this->assertGuest();
    }

    public function test_a_user_can_sign_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson(route('api.login'), ['email' => 'ada@example.com', 'password' => 'wrong'])
            ->assertJsonValidationErrors('email');
        $this->assertGuest();

        $this->postJson(route('api.login'), ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user);

        $this->postJson(route('api.logout'))->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_the_current_user_includes_todays_token_usage(): void
    {
        config(['rag.limits.daily_tokens_per_user' => 5000]);

        $this->actingAs(User::factory()->create(['name' => 'Ada']))
            ->getJson(route('api.user'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada')
            ->assertJsonPath('data.tokens_used_today', 0)
            ->assertJsonPath('data.daily_token_limit', 5000);
    }
}
