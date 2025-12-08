<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()
            ->json(['status' => 'ok'])
            ->cookie(
                'XSRF-TOKEN',
                $request->session()->token(),
                0,
                '/',
                config('session.domain'),
                config('session.secure'),
                false,
                false,
                config('session.same_site', 'lax')
            );
    }
}
