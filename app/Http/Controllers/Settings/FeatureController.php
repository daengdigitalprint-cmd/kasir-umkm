<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Support\OptionalFeatures;
use App\Support\UiMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Fitur Tambahan": nyalakan fitur lanjutan satu per satu.
 */
class FeatureController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->store;
        abort_if(! $store, 404);

        return Inertia::render('settings/Features', [
            'groups' => OptionalFeatures::forFrontend($store),
            'uiMode' => UiMode::of($store),
        ]);
    }

    public function update(Request $request, string $feature): RedirectResponse
    {
        $store = $request->user()->store;
        abort_if(! $store || ! OptionalFeatures::exists($feature), 404);

        $enabled = $request->validate(['enabled' => 'required|boolean'])['enabled'];
        $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        $label = OptionalFeatures::FEATURES[$feature]['label'];

        if (! OptionalFeatures::isToggleable($store, $feature)) {
            return back()->with('info', "\"{$label}\" selalu tampil di Mode Lengkap.");
        }

        if ($enabled) {
            if (OptionalFeatures::needsUpgrade($store, $feature)) {
                return back()->with('error', "\"{$label}\" tersedia di paket berbayar. Upgrade paket untuk mengaktifkannya.");
            }
            OptionalFeatures::enable($store, $feature);
        } else {
            if (OptionalFeatures::isInUse($store, $feature)) {
                return back()->with('error', "\"{$label}\" belum bisa dimatikan karena masih ada data yang berjalan.");
            }
            OptionalFeatures::disable($store, $feature);
        }

        ActivityLogger::log(
            store: $store,
            action: $enabled ? 'feature.enabled' : 'feature.disabled',
            description: "Fitur \"{$label}\" ".($enabled ? 'diaktifkan' : 'dimatikan').' oleh '.($request->user()->name ?? 'System'),
            metadata: ['feature' => $feature],
        );

        return back()->with('success', "\"{$label}\" ".($enabled ? 'aktif. Menunya sudah tampil di sidebar.' : 'dimatikan.'));
    }
}
