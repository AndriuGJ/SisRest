<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->activo) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'La cuenta está desactivada.');
            }

            return redirect()->route('login')->withErrors([
                'username' => 'La cuenta está desactivada. Comunícate con el administrador.',
            ]);
        }

        return $next($request);
    }
}
