<?php

namespace App\Http\Middleware;

use App\Services\AffiliateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menangkap ?ref=KODE di halaman mana pun (beranda, blog, halaman jenis usaha, form daftar):
 * afiliator diingat lewat cookie dan kliknya dicatat. Link pendek /r/KODE ditangani
 * AffiliateLinkController.
 */
class CaptureAffiliateReferral
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->filled('ref') && is_string($request->query('ref'))) {
            // Atribusi tidak boleh pernah menggagalkan halaman yang dibuka pengunjung.
            rescue(function () use ($request) {
                $affiliate = AffiliateService::findByCode($request->query('ref'));
                if ($affiliate) {
                    AffiliateService::rememberVisit($request, $affiliate);
                }
            });
        }

        return $next($request);
    }
}
