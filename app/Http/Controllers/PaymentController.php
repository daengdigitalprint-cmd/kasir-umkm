<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function processPayment(Request $request)
    {
        $request->validate([
            'plan' => 'required|in:basic,premium,enterprise',
            'payment_method' => 'required|in:bank_transfer,e_wallet,qris,cash',
        ]);

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Toko tidak ditemukan.',
            ], 404);
        }

        if ($store->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Toko sudah aktif.',
            ], 400);
        }

        try {
            DB::transaction(function () use ($store, $request) {
                // Activate store
                $store->is_active = true;
                $store->subscription_start = now();
                $store->subscription_end = now()->addMonth();
                $store->subscription_days = 30;
                $store->is_subscription_active = true;
                $store->subscription_notes = "Aktivasi via pembayaran - Paket {$request->plan}";
                $store->save();

                // Here you would typically:
                // 1. Create payment record
                // 2. Send payment notification
                // 3. Integrate with payment gateway
                // 4. Send confirmation email
            });

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil! Toko Anda telah diaktifkan.',
                'redirect' => route('dashboard'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses pembayaran. Silakan coba lagi.',
            ], 500);
        }
    }

    public function getPlans()
    {
        return response()->json([
            'plans' => [
                'basic' => [
                    'name' => 'Paket Basic',
                    'price' => 50000,
                    'duration' => 30,
                    'features' => [
                        'Hingga 30 produk',
                        'Laporan penjualan',
                        'Support email',
                    ],
                ],
                'premium' => [
                    'name' => 'Paket Premium',
                    'price' => 100000,
                    'duration' => 30,
                    'features' => [
                        'Hingga 500 produk',
                        'Laporan lengkap',
                        'Export Excel/PDF',
                        'Support prioritas',
                    ],
                ],
                'enterprise' => [
                    'name' => 'Paket Enterprise',
                    'price' => 200000,
                    'duration' => 30,
                    'features' => [
                        'Produk unlimited',
                        'Analytics mendalam',
                        'API access',
                        'Support 24/7',
                    ],
                ],
            ],
        ]);
    }
}
