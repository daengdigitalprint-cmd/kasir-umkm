<?php

namespace App\Http\Middleware;

use App\Support\PlanModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->user()?->store()->first();

        // Asisten AI memakai API berbayar: tidak termasuk paket Gratis.
        if ($store && PlanModules::locked($store, 'ai_chat')) {
            $message = PlanModules::lockedMessage('ai_chat');
            if ($request->routeIs('ai.chat.page')) {
                return redirect()->route('dashboard')->with('warning', $message);
            }

            abort(403, $message);
        }

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
