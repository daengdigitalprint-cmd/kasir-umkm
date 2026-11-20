<?php

namespace App\Http\Controllers;

use App\Support\Kompetitor;
use App\Support\LandingPricing;
use Illuminate\Contracts\View\View;

/** Halaman SEO "Alternatif [kompetitor]" (Blade murni, data di App\Support\Kompetitor). */
class AlternatifController extends Controller
{
    public function show(string $kompetitor): View
    {
        $data = Kompetitor::find($kompetitor);
        abort_if($data === null, 404);

        return view('alternatif.show', [
            'k' => $data,
            'pricing' => LandingPricing::payload(),
            'others' => array_values(array_filter(Kompetitor::all(), fn (array $o) => $o['key'] !== $kompetitor)),
        ]);
    }
}
