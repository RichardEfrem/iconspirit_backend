<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt(['username' => $request->username, 'password' => $request->password])) {
            return $this->errorResponse('Invalid credentials', 401);
        }

        $user = Auth::user();

        if (!$user instanceof User || !$user->isActive()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            return $this->errorResponse('Account is deactivated. Contact an administrator.', 403);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->successResponse(['user' => $user], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->successResponse(null, 'Logged out successfully')
            ->withCookie(Cookie::forget(config('session.cookie')))
            ->withCookie(Cookie::forget('XSRF-TOKEN'));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->successResponse(['user' => $request->user()], 'Authenticated user');
    }
}
