<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->user()?->store()->first();

        if ($store && (($store->settings['ai_chat_enabled'] ?? true) === false)) {
            if ($request->routeIs('ai.chat.page')) {
                return redirect()->route('dashboard')
                    ->with('error', 'Chat AI dinonaktifkan untuk toko ini.');
            }

            abort(403, 'Chat AI dinonaktifkan untuk toko ini.');
        }

        return $next($request);
    }
}
