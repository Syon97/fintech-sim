<?php

namespace App\Http\Controllers;

use App\Access\AuthAudit;
use App\Access\Permissions;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $username = strtolower(trim($data['username']));

        if (! Auth::attempt(['username' => $username, 'password' => $data['password'], 'active' => true])) {
            AuthAudit::record('login_failed', $username, $request);

            // One message for every cause, so the answer never reveals which usernames exist.
            throw ValidationException::withMessages([
                'username' => ["That username and password don't match. Check both and try again."],
            ]);
        }

        $request->session()->regenerate();
        AuthAudit::record('login', $username, $request);

        return response()->json(self::present(Auth::user()));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(self::present($request->user()));
    }

    public function logout(Request $request): Response
    {
        AuthAudit::record('logout', $request->user()->username, $request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private static function present(User $user): array
    {
        return [
            'username' => $user->username,
            'name' => $user->name,
            'role' => $user->role,
            'permissions' => Permissions::for($user->role),
        ];
    }
}