<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Services\AffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Link pendek afiliator: /r/KODE → catat klik, ingat afiliator, lalu ke beranda. */
class AffiliateLinkController extends Controller
{
    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $affiliate = AffiliateService::findByCode($code);

        if ($affiliate) {
            rescue(fn () => AffiliateService::rememberVisit($request, $affiliate));
        }

        // Kode salah atau afiliator dibekukan: pengunjung tetap sampai ke beranda.
        return redirect('/');
    }
}
