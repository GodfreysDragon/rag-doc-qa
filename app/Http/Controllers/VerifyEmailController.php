<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;

/**
 * The link in the verification email. The signed URL proves it came from the
 * email, so it works even when opened in a browser that isn't signed in.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->route('home', ['verified' => 1]);
    }
}
