<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\Rag\TokenBudget;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Session (cookie) authentication for the SPA, which is served from the same
 * origin, so no API tokens are needed.
 */
class AuthController extends Controller
{
    public function show(Request $request, TokenBudget $budget): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user(), $budget)]);
    }

    public function register(RegisterRequest $request, TokenBudget $budget): JsonResponse
    {
        $user = User::create($request->validated());

        // Sends the verification email.
        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $this->userPayload($user, $budget)], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request, TokenBudget $budget): JsonResponse
    {
        if (! Auth::attempt($request->safe()->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return response()->json(['data' => $this->userPayload($request->user(), $budget)]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function resendVerification(Request $request): Response
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->noContent();
    }

    /**
     * Delete the account with all of its documents. Chunks and document rows
     * go with the user through cascading foreign keys; the files are removed here.
     */
    public function destroy(DeleteAccountRequest $request): Response
    {
        $user = $request->user();

        Storage::disk('local')->delete($user->documents()->pluck('path')->all());

        Auth::guard('web')->logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * @return array{id: int, name: string, email: string, email_verified: bool, verification_required: bool, tokens_used_today: int, daily_token_limit: int}
     */
    private function userPayload(User $user, TokenBudget $budget): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'verification_required' => (bool) config('rag.auth.verify_email'),
            'tokens_used_today' => $budget->usedBy($user),
            'daily_token_limit' => (int) config('rag.limits.daily_tokens_per_user'),
        ];
    }
}
