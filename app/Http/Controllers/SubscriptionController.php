<?php

namespace App\Http\Controllers;

use App\Models\AttendancePayrollPricing;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransferSetting;
use App\Models\Voucher;
use App\Services\AttendancePayrollAddOnService;
use App\Services\OutletAddOnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    /**
     * Show subscription page for store owners
     */
    public function index()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('dashboard');
        }

        $plans = SubscriptionPlan::active()->ordered()->get();

        $planPayload = $plans->map(function (SubscriptionPlan $plan): array {
            $cycle = $plan->billing_cycle ?? 'monthly';
            if (! in_array($cycle, ['monthly', 'yearly'], true)) {
                $cycle = 'monthly';
            }

            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'description' => $plan->description ?? '',
                'price' => (float) $plan->price,
                'duration_days' => (int) $plan->duration_days,
                'max_products' => $plan->max_products,
                'max_customers' => $plan->max_customers,
                'features' => $plan->features ?? [],
                'is_active' => (bool) $plan->is_active,
                'is_popular' => (bool) $plan->is_popular,
                'billing_cycle' => $cycle,
            ];
        })->values()->all();

        $yearlyDiscountPercentLabel = $this->bisnisYearlySavingsPercentLabel($plans);

        $latestPayment = SubscriptionPayment::where('store_id', $store->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $paymentHistory = SubscriptionPayment::where('store_id', $store->id)
            ->with('plan')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Hitung outlet terpakai & kuota per grup usaha (Gudang Pusat tidak ikut kuota).
        $organizationId = $store->organization_id;
        $currentOutletCount = $organizationId
            ? \App\Models\Store::where('organization_id', $organizationId)->outlet()->count()
            : 1;
        $extraOutlets = (int) ($store->extra_outlets ?? 0);
        // Batas dasar dari paket (max_stores); fallback ke max_stores paket toko ini bila grup null.
        $baseLimit = $organizationId
            ? \App\Support\StorePlanLimits::maxOutletsAllowedForOrganization($organizationId)
            : ($store->subscriptionPlan?->max_stores);
        // null = tidak dibatasi.
        $freeOutletLimit = $store->subscriptionPlan?->max_stores;
        $totalLimit = $baseLimit; // sudah termasuk extra_outlets bila dari maxOutletsAllowedForOrganization

        return Inertia::render('Subscription/Index', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'is_active' => $store->is_active,
                'subscription_start' => $store->subscription_start,
                'subscription_end' => $store->subscription_end,
                'subscription_days' => $store->subscription_days,
                'is_subscription_active' => $store->is_subscription_active,
                'organization_id' => $organizationId,
            ],
            'outletInfo' => [
                'current_count' => $currentOutletCount,
                'free_limit' => $freeOutletLimit,
                'extra_outlets' => $extraOutlets,
                'total_limit' => $totalLimit,
                'is_unlimited' => $totalLimit === null,
                'can_buy_addon' => $store->subscriptionPlan && ! in_array($store->subscriptionPlan->slug, ['starter', 'starter-tahunan'], true),
                'price_per_outlet_monthly' => OutletAddOnService::PRICE_PER_OUTLET_MONTHLY,
                'has_pending_addon' => SubscriptionPayment::where('store_id', $store->id)
                    ->where('type', 'outlet_addon')
                    ->where('status', 'pending')
                    ->exists(),
            ],
            'attendancePayrollInfo' => [
                'is_purchased' => AttendancePayrollAddOnService::isPurchased($store),
                'expires_at' => AttendancePayrollAddOnService::attendancePayrollUntil($store),
                'pricing' => AttendancePayrollPricing::current()->toPayload(),
                'has_pending_addon' => SubscriptionPayment::where('store_id', $store->id)
                    ->where('type', 'attendance_payroll_addon')
                    ->where('status', 'pending')
                    ->exists(),
            ],
            'plans' => $planPayload,
            'yearlyDiscountPercentLabel' => $yearlyDiscountPercentLabel,
            'latestPayment' => $latestPayment,
            'paymentHistory' => $paymentHistory,
        ]);
    }

    /**
     * Persentase hemat paket GrowUp tahunan vs membayar GrowUp bulanan 12 kali (format id-ID, satu desimal).
     */
    private function bisnisYearlySavingsPercentLabel(\Illuminate\Support\Collection $plans): string
    {
        $bisnisMonthly = $plans->first(function (SubscriptionPlan $p) {
            return $p->slug === 'bisnis' && ($p->billing_cycle ?? 'monthly') === 'monthly';
        });
        $bisnisYearly = $plans->first(fn (SubscriptionPlan $p) => $p->slug === 'bisnis-tahunan');

        if (! $bisnisMonthly || ! $bisnisYearly) {
            return '16,7';
        }

        $monthly = (float) $bisnisMonthly->price;
        $yearly = (float) $bisnisYearly->price;

        if ($monthly <= 0 || $yearly <= 0) {
            return '16,7';
        }

        $fullYearFromMonthly = $monthly * 12;
        if ($fullYearFromMonthly <= 0) {
            return '16,7';
        }

        $pct = (($fullYearFromMonthly - $yearly) / $fullYearFromMonthly) * 100;
        if ($pct <= 0 || $pct >= 100) {
            return '16,7';
        }

        return number_format(round($pct, 1), 1, ',', '');
    }

    /**
     * Show checkout page for a specific plan
     */
    public function checkout(SubscriptionPlan $plan)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('dashboard');
        }

        if (! $plan->is_active) {
            return redirect()->route('subscription.index');
        }

        if ((float) $plan->price <= 0) {
            return redirect()->route('subscription.index')
                ->with('info', 'Paket ini tidak memerlukan pembayaran melalui halaman checkout.');
        }

        // Check if there's already a pending payment
        $hasPending = SubscriptionPayment::where('store_id', $store->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return redirect()->route('subscription.index');
        }

        $cycle = $plan->billing_cycle ?? 'monthly';
        if (! in_array($cycle, ['monthly', 'yearly'], true)) {
            $cycle = 'monthly';
        }

        $transfer = SubscriptionTransferSetting::current();

        return Inertia::render('Subscription/Checkout', [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'description' => $plan->description ?? '',
                'price' => (float) $plan->price,
                'duration_days' => (int) $plan->duration_days,
                'max_products' => $plan->max_products,
                'max_customers' => $plan->max_customers,
                'features' => $plan->features ?? [],
                'is_popular' => (bool) $plan->is_popular,
                'billing_cycle' => $cycle,
            ],
            'storeName' => $store->name,
            'transferSettings' => $transfer->toCheckoutPayload(),
        ]);
    }

    /**
     * Checkout page for buying outlet add-ons.
     */
    public function checkoutOutlet()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('dashboard');
        }

        $canBuyAddon = $store->subscriptionPlan && ! in_array($store->subscriptionPlan->slug, ['starter', 'starter-tahunan'], true);

        if (! $canBuyAddon) {
            return redirect()->route('subscription.index');
        }

        $hasPending = SubscriptionPayment::where('store_id', $store->id)
            ->where('type', 'outlet_addon')
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return redirect()->route('subscription.index');
        }

        $transfer = SubscriptionTransferSetting::current();

        return Inertia::render('Subscription/OutletCheckout', [
            'outletInfo' => [
                'current_count' => $store->organization_id
                    ? \App\Models\Store::where('organization_id', $store->organization_id)->outlet()->count()
                    : 1,
                'price_per_outlet_monthly' => OutletAddOnService::PRICE_PER_OUTLET_MONTHLY,
            ],
            'storeName' => $store->name,
            'transferSettings' => $transfer->toCheckoutPayload(),
        ]);
    }

    /**
     * Submit payment for outlet add-on (with proof upload).
     */
    public function submitPaymentOutlet(Request $request)
    {
        $validated = $request->validate([
            'outlets' => 'required|integer|min:1|max:100',
            'billing_cycle' => 'nullable|string|in:monthly,yearly',
            'payment_method' => 'required|string|in:bank_transfer,qris',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Toko tidak ditemukan.'], 422);
        }

        try {
            $payment = OutletAddOnService::createPayment(
                $store,
                (int) $validated['outlets'],
                $validated['payment_method'],
                $validated['notes'] ?? null,
                $user,
                $validated['billing_cycle'] ?? null,
            );
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($request->hasFile('payment_proof')) {
            $path = $request->file('payment_proof')->store('payment-proofs', 'public');
            $payment->update(['payment_proof' => $path]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Validasi kode voucher untuk checkout paket (price voucher = nominal potongan).
     */
    public function applyVoucher(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'code' => 'required|string|max:50',
        ]);

        $plan = SubscriptionPlan::findOrFail($request->plan_id);

        if (! $plan->is_active || (float) $plan->price <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Paket tidak valid untuk checkout.',
            ], 422);
        }

        $result = $this->resolveCheckoutVoucher(trim($request->code), $plan);

        if (! $result['ok']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        $planPrice = (float) $plan->price;
        $amountDue = $result['amount'];

        return response()->json([
            'success' => true,
            'voucher' => [
                'name' => $result['voucher']->name,
                'code' => $result['voucher']->code,
            ],
            'plan_price' => $planPrice,
            'amount_due' => $amountDue,
            'discount_amount' => round($planPrice - $amountDue, 2),
        ]);
    }

    /**
     * Submit a subscription payment request
     */
    public function submitPayment(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method' => 'required|in:bank_transfer,qris',
            'payment_proof' => 'nullable|image|max:5120', // 5MB max
            'notes' => 'nullable|string|max:500',
            'voucher_code' => 'nullable|string|max:50',
        ]);

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Toko tidak ditemukan.',
            ], 404);
        }

        // Check if there's already a pending payment
        $existingPending = SubscriptionPayment::where('store_id', $store->id)
            ->where('status', 'pending')
            ->first();

        if ($existingPending) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah memiliki pembayaran yang menunggu konfirmasi.',
            ], 400);
        }

        $plan = SubscriptionPlan::findOrFail($request->plan_id);

        if ((float) $plan->price <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Paket ini tidak dapat dibayar melalui formulir ini.',
            ], 422);
        }

        $voucherId = null;
        $amount = round((float) $plan->price, 2);

        if ($request->filled('voucher_code')) {
            $resolved = $this->resolveCheckoutVoucher(trim((string) $request->voucher_code), $plan);
            if (! $resolved['ok']) {
                return response()->json([
                    'success' => false,
                    'message' => $resolved['message'],
                ], 422);
            }
            $amount = $resolved['amount'];
            $voucherId = $resolved['voucher']->id;
        }

        $paymentData = [
            'store_id' => $store->id,
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'voucher_id' => $voucherId,
            'amount' => $amount,
            'duration_days' => $plan->duration_days,
            'payment_method' => $request->payment_method,
            'notes' => $request->notes,
            'status' => 'pending',
        ];

        // Handle payment proof upload
        if ($request->hasFile('payment_proof')) {
            $path = $request->file('payment_proof')->store('payment-proofs', 'public');
            $paymentData['payment_proof'] = $path;
        }

        $payment = SubscriptionPayment::create($paymentData);

        return response()->json([
            'success' => true,
            'message' => 'Pembayaran berhasil diajukan! Silakan tunggu konfirmasi dari admin.',
            'payment' => $payment,
        ]);
    }

    /**
     * Upload payment proof for an existing payment
     */
    public function uploadProof(Request $request, SubscriptionPayment $payment)
    {
        $request->validate([
            'payment_proof' => 'required|image|max:5120',
        ]);

        $user = auth()->user();
        if ($payment->user_id !== $user->id) {
            abort(403);
        }

        if (! $payment->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran ini tidak dalam status menunggu konfirmasi.',
            ], 400);
        }

        // Delete old proof if exists
        if ($payment->payment_proof) {
            Storage::disk('public')->delete($payment->payment_proof);
        }

        $path = $request->file('payment_proof')->store('payment-proofs', 'public');
        $payment->update(['payment_proof' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Bukti pembayaran berhasil diunggah.',
        ]);
    }

    /**
     * Submit pembayaran add-on outlet tambahan.
     */
    public function submitOutletAddOn(Request $request)
    {
        $request->validate([
            'outlets' => 'required|integer|min:1|max:100',
            'payment_method' => 'required|in:bank_transfer,qris',
            'payment_proof' => 'nullable|image|max:5120',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Toko tidak ditemukan.'], 404);
        }

        try {
            $payment = OutletAddOnService::createPayment(
                $store,
                (int) $request->outlets,
                $request->payment_method,
                $request->notes,
            );

            if ($request->hasFile('payment_proof')) {
                $path = $request->file('payment_proof')->store('payment-proofs', 'public');
                $payment->update(['payment_proof' => $path]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan pembelian outlet tambahan berhasil diajukan! Silakan tunggu konfirmasi dari admin.',
                'payment' => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Checkout page for buying Absensi + Payroll add-on.
     */
    public function checkoutAttendancePayroll()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('dashboard');
        }

        $hasPending = SubscriptionPayment::where('store_id', $store->id)
            ->where('type', 'attendance_payroll_addon')
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return redirect()->route('subscription.index');
        }

        $transfer = SubscriptionTransferSetting::current();

        return Inertia::render('Subscription/AttendancePayrollCheckout', [
            'pricing' => AttendancePayrollPricing::current()->toPayload(),
            'storeName' => $store->name,
            'transferSettings' => $transfer->toCheckoutPayload(),
        ]);
    }

    /**
     * Submit pembayaran add-on Absensi + Payroll.
     */
    public function submitAttendancePayroll(Request $request)
    {
        $request->validate([
            'billing_cycle' => 'required|string|in:monthly,yearly',
            'payment_method' => 'required|in:bank_transfer,qris',
            'payment_proof' => 'nullable|image|max:5120',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Toko tidak ditemukan.'], 404);
        }

        try {
            $payment = AttendancePayrollAddOnService::createPayment(
                $store,
                $request->payment_method,
                $request->notes,
                $user,
                $request->billing_cycle,
            );

            if ($request->hasFile('payment_proof')) {
                $path = $request->file('payment_proof')->store('payment-proofs', 'public');
                $payment->update(['payment_proof' => $path]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan pembelian paket Absensi + Payroll berhasil diajukan! Silakan tunggu konfirmasi dari admin.',
                'payment' => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * @return array{ok: bool, message?: string, voucher?: Voucher, amount?: float}
     */
    private function resolveCheckoutVoucher(string $code, SubscriptionPlan $plan): array
    {
        if ($code === '') {
            return ['ok' => false, 'message' => 'Masukkan kode voucher.'];
        }

        $voucher = Voucher::query()
            ->whereRaw('upper(trim(code)) = upper(trim(?))', [$code])
            ->first();

        if (! $voucher) {
            return ['ok' => false, 'message' => 'Kode voucher tidak ditemukan.'];
        }

        if (! $voucher->is_active) {
            return ['ok' => false, 'message' => 'Voucher tidak aktif.'];
        }

        $now = now();
        if ($voucher->valid_from && $now->lt($voucher->valid_from)) {
            return ['ok' => false, 'message' => 'Voucher belum berlaku.'];
        }

        if ($voucher->valid_until && $now->gt($voucher->valid_until)) {
            return ['ok' => false, 'message' => 'Voucher sudah kedaluwarsa.'];
        }

        $planPrice = round((float) $plan->price, 2);
        $voucherDiscount = round((float) $voucher->price, 2);

        if ($voucherDiscount <= 0) {
            return ['ok' => false, 'message' => 'Voucher ini tidak bisa dipakai untuk pembayaran paket.'];
        }

        if ($voucherDiscount > $planPrice) {
            return ['ok' => false, 'message' => 'Voucher tidak berlaku untuk harga paket ini.'];
        }

        $amountDue = round(max(0, $planPrice - $voucherDiscount), 2);

        $pendingWithVoucher = SubscriptionPayment::query()
            ->where('voucher_id', $voucher->id)
            ->where('status', 'pending')
            ->count();

        if ($voucher->usage_limit !== null
            && ($voucher->used_count + $pendingWithVoucher) >= $voucher->usage_limit) {
            return ['ok' => false, 'message' => 'Kuota voucher sudah habis.'];
        }

        return [
            'ok' => true,
            'voucher' => $voucher,
            'amount' => $amountDue,
        ];
    }
}
