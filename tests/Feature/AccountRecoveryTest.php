<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_registering_sends_a_verification_email(): void
    {
        $this->postJson(route('api.register'), [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])
            ->assertCreated()
            ->assertJsonPath('data.email_verified', false);

        Notification::assertSentTo(User::sole(), VerifyEmail::class);
    }

    public function test_the_emailed_link_verifies_the_address(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user))->assertRedirect(route('home', ['verified' => 1]));

        $this->assertTrue($user->refresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_or_unsigned_verification_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user, hash: sha1('someone@else.com')))->assertForbidden();
        $this->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden();

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_unverified_users_are_blocked_only_when_verification_is_required(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->getJson(route('api.documents.index'))->assertOk();

        config(['rag.auth.verify_email' => true]);

        $this->actingAs($user)->getJson(route('api.documents.index'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Verify your email address first. Check your inbox for the link.');
        $this->actingAs($user)->post(route('api.chat'), ['question' => 'Hi'], ['Accept' => 'text/event-stream'])->assertForbidden();
        $this->actingAs($user)->getJson(route('api.user'))
            ->assertOk()
            ->assertJsonPath('data.verification_required', true);

        $user->markEmailAsVerified();
        $this->actingAs($user)->getJson(route('api.documents.index'))->assertOk();
    }

    public function test_the_verification_email_can_be_sent_again(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson(route('api.verification.send'))->assertNoContent();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);

        $verified = User::factory()->create();
        $this->actingAs($verified)->postJson(route('api.verification.send'))->assertNoContent();
        Notification::assertNotSentTo($verified, VerifyEmail::class);
    }

    public function test_a_reset_link_is_sent_without_revealing_whether_the_account_exists(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $known = $this->postJson(route('api.password.email'), ['email' => 'ada@example.com'])->assertOk();
        $unknown = $this->postJson(route('api.password.email'), ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, url("/reset-password/{$notification->token}"));
        });
        Notification::assertCount(1);
    }

    public function test_the_reset_page_opens_the_app(): void
    {
        $this->get(route('password.reset', ['token' => 'abc']))->assertOk()->assertSee('id="app"', escape: false);
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = Password::createToken($user);
        $reset = fn (string $token): array => [
            'token' => $token,
            'email' => 'ada@example.com',
            'password' => 'new-correct-horse',
            'password_confirmation' => 'new-correct-horse',
        ];

        $this->postJson(route('api.password.update'), $reset('wrong-token'))->assertJsonValidationErrors('email');
        $this->postJson(route('api.password.update'), $reset($token))->assertOk();

        $this->assertTrue(Hash::check('new-correct-horse', $user->refresh()->password));
        $this->postJson(route('api.login'), ['email' => 'ada@example.com', 'password' => 'new-correct-horse'])->assertOk();
    }

    private function verificationUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => $hash ?? sha1($user->getEmailForVerification()),
        ]);
    }
}
