<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alihkan (301) semua varian alamat ke satu alamat utama dari APP_URL,
 * mis. http://www.kasirumkm.web.id/x -> https://kasirumkm.web.id/x.
 *
 * Tanpa ini www/non-www dan http/https sama-sama melayani halaman (200),
 * sehingga Google menganggapnya situs kembar dan memilih canonical sendiri.
 * Hanya aktif di production dan hanya untuk GET/HEAD (POST tidak dialihkan
 * agar form/webhook tidak kehilangan body).
 */
class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production') || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $canonical = parse_url((string) config('app.url'));
        $canonicalHost = strtolower($canonical['host'] ?? '');
        $canonicalScheme = strtolower($canonical['scheme'] ?? 'https');

        if ($canonicalHost === '') {
            return $next($request);
        }

        $hostMismatch = strtolower($request->getHost()) !== $canonicalHost;
        $schemeMismatch = $canonicalScheme === 'https' && ! $this->isHttps($request);

        if (! $hostMismatch && ! $schemeMismatch) {
            return $next($request);
        }

        $port = isset($canonical['port']) ? ':'.$canonical['port'] : '';

        return redirect()->away($canonicalScheme.'://'.$canonicalHost.$port.$request->getRequestUri(), 301);
    }

    /**
     * HTTPS bisa diterminasi di nginx; cek juga header proxy dan variabel server
     * supaya permintaan HTTPS tidak dialihkan berulang (redirect loop).
     */
    private function isHttps(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        if (strtolower((string) $request->header('X-Forwarded-Proto')) === 'https') {
            return true;
        }

        $https = strtolower((string) $request->server('HTTPS'));

        return $https === 'on' || $https === '1' || (string) $request->server('SERVER_PORT') === '443';
    }
}
