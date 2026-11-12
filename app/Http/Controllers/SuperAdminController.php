<?php

namespace App\Http\Controllers;

use App\Models\AttendancePayrollPricing;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransferSetting;
use App\Models\TrialActivationLog;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\AttendancePayrollAddOnService;
use App\Services\OutletAddOnService;
use App\Services\TrialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SuperAdminController extends Controller
{
    public function dashboard()
    {
        $totalStores = Store::count();
        $activeStores = Store::where('is_active', true)->count();

        $timeBoundSubscribedStores = Store::where('is_subscription_active', true)
            ->whereNotNull('subscription_end')
            ->whereDoesntHave('subscriptionPlan', function ($q) {
                $q->whereIn('slug', ['starter', 'starter-tahunan'])
                    ->where('price', 0);
            });

        $expiredStores = (clone $timeBoundSubscribedStores)
            ->where('subscription_end', '<', now())
            ->count();
        $expiringSoon = (clone $timeBoundSubscribedStores)
            ->where('subscription_end', '>', now())
            ->where('subscription_end', '<=', now()->addDays(7))
            ->count();

        $pendingPayments = SubscriptionPayment::where('status', 'pending')->count();
        $totalRevenue = SubscriptionPayment::where('status', 'confirmed')->sum('amount');

        $stores = Store::with([
            'users',
            'voucherUsages',
            'subscriptionPlan:id,slug,price',
            // Pemilik = anggota admin/owner pertama (pendaftar yang membuat toko).
            'members' => fn ($q) => $q->select('users.id', 'users.name', 'users.email', 'users.phone')
                ->wherePivotIn('role', ['owner', 'admin'])
                ->orderBy('store_user.created_at')
                ->orderBy('users.id'),
        ])
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->through(fn (Store $s) => array_merge($s->toArray(), [
                'pro_trial_used' => $s->pro_trial_used,
                'pro_trial_active' => $s->pro_trial_active,
                'pro_trial_start' => $s->pro_trial_start?->toISOString(),
                'pro_trial_end' => $s->pro_trial_end?->toISOString(),
                'owner' => ($owner = $s->members->first()) ? [
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'phone' => $owner->phone,
                ] : null,
            ]));

        $vouchers = Voucher::orderBy('created_at', 'desc')->get();
        $plans = SubscriptionPlan::ordered()->get();

        // Recent pending payments
        $recentPayments = SubscriptionPayment::with(['store', 'user', 'plan'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $transfer = SubscriptionTransferSetting::current();

        return Inertia::render('SuperAdmin/Dashboard', [
            'stats' => [
                'total_stores' => $totalStores,
                'active_stores' => $activeStores,
                'expired_stores' => $expiredStores,
                'expiring_soon' => $expiringSoon,
                'pending_payments' => $pendingPayments,
                'total_revenue' => $totalRevenue,
            ],
            'stores' => $stores,
            'vouchers' => $vouchers,
            'plans' => $plans,
            'recentPayments' => $recentPayments,
            'transferSettings' => $transfer->toCheckoutPayload(),
            'attendancePayrollPricing' => AttendancePayrollPricing::current()->toPayload(),
            'user' => [
                'name' => auth()->user()->name,
            ],
        ]);
    }

    /**
     * Pengaturan rekening & gambar QRIS untuk pembayaran langganan (checkout pengguna).
     */
    public function updateTransferSettings(Request $request)
    {
        $request->validate([
            'bank_name' => 'nullable|string|max:100',
            'account_holder' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'qris_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_qris' => 'nullable|boolean',
        ]);

        $setting = SubscriptionTransferSetting::current();

        $setting->fill([
            'bank_name' => $request->input('bank_name') ?: null,
            'account_holder' => $request->input('account_holder') ?: null,
            'account_number' => $request->input('account_number') ?: null,
        ]);

        if ($request->boolean('remove_qris') && $setting->qris_path) {
            Storage::disk('public')->delete($setting->qris_path);
            $setting->qris_path = null;
        }

        if ($request->hasFile('qris_image')) {
            if ($setting->qris_path) {
                Storage::disk('public')->delete($setting->qris_path);
            }
            $setting->qris_path = $request->file('qris_image')->store('subscription-qris', 'public');
        }

        $setting->save();

        return redirect()->back()->with('success', 'Pengaturan rekening & QRIS untuk pembayaran langganan disimpan.');
    }

    /**
     * Pengaturan harga add-on Absensi + Payroll.
     */
    public function updateAttendancePayrollPricing(Request $request)
    {
        $request->validate([
            'monthly_price' => 'required|integer|min:0|max:100000000',
            'yearly_price' => 'required|integer|min:0|max:100000000',
        ]);

        $pricing = AttendancePayrollPricing::current();
        $pricing->update([
            'monthly_price' => $request->integer('monthly_price'),
            'yearly_price' => $request->integer('yearly_price'),
        ]);

        return redirect()->back()->with('success', 'Harga paket Absensi + Payroll disimpan.');
    }

    public function extendSubscription(Request $request, Store $store)
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($store, $validated) {
            $store->extendSubscription($validated['days']);

            if (! empty($validated['notes'])) {
                $store->subscription_notes = $validated['notes'];
                $store->save();
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Masa aktif toko {$store->name} berhasil diperpanjang {$validated['days']} hari.",
            'store' => [
                'id' => $store->id,
                'subscription_end' => $store->subscription_end?->toISOString(),
                'subscription_days' => $store->subscription_days,
                'is_subscription_active' => $store->is_subscription_active,
            ],
        ]);
    }

    public function reduceSubscription(Request $request, Store $store)
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($store, $validated) {
            $store->reduceSubscription($validated['days']);

            if (! empty($validated['notes'])) {
                $store->subscription_notes = $validated['notes'];
                $store->save();
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Masa aktif toko {$store->name} berhasil dikurangi {$validated['days']} hari.",
            'store' => [
                'id' => $store->id,
                'subscription_end' => $store->subscription_end?->toISOString(),
                'subscription_days' => $store->subscription_days,
                'is_subscription_active' => $store->is_subscription_active,
            ],
        ]);
    }

    public function toggleStoreStatus(Store $store)
    {
        $store->is_active = ! $store->is_active;
        $store->save();

        $status = $store->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'success' => true,
            'message' => "Toko {$store->name} berhasil {$status}.",
            'store' => [
                'id' => $store->id,
                'is_active' => $store->is_active,
            ],
        ]);
    }

    /**
     * Delete a store and all associated data.
     * Accessible only by SuperAdmin.
     */
    public function deleteStore(Store $store)
    {
        // Warn if this store is part of a larger organization (phase F1 check)
        // For now we allow deletion as the superadmin has the ultimate power.

        DB::transaction(function () use ($store) {
            // Decouple users before cascade delete
            $store->users()->update(['store_id' => null]);
            $store->members()->detach();

            // Clear storage assets
            if ($store->logo) {
                Storage::disk('public')->delete($store->logo);
            }
            if ($store->receipt_logo) {
                Storage::disk('public')->delete($store->receipt_logo);
            }

            // The database handles most other deletions via cascades (products, customers, transactions, etc)
            // but we explicitly delete these just to trigger any model-level hooks if they exist.
            $store->stockMovements()->delete();
            $store->productCategories()->delete();
            $store->products()->delete();
            $store->customers()->delete();
            $store->transactions()->delete();
            $store->discounts()->delete();
            $store->voucherUsages()->delete();
            $store->subscriptionPayments()->delete();
            $store->chartOfAccounts()->delete();
            $store->journalEntries()->delete();
            $store->cashShifts()->delete();
            $store->suppliers()->delete();
            $store->purchases()->delete();
            $store->purchaseReturns()->delete();
            $store->preOrders()->delete();

            $store->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Toko dan seluruh datanya berhasil dihapus permanen.',
        ]);
    }

    // ===== Pro Trial Management =====

    public function activateProTrial(Store $store)
    {
        try {
            TrialService::activate($store, auth()->user());

            return response()->json([
                'success' => true,
                'message' => "Trial 7 hari paket Pro berhasil diaktifkan untuk toko {$store->name}.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function stopProTrial(Store $store)
    {
        try {
            TrialService::stop($store, auth()->user());

            return response()->json([
                'success' => true,
                'message' => "Trial Pro untuk toko {$store->name} berhasil dihentikan.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function trialLogs()
    {
        $logs = TrialActivationLog::with('store:id,name,slug', 'admin:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('SuperAdmin/TrialLogs', [
            'logs' => $logs,
            'user' => [
                'name' => auth()->user()->name,
            ],
        ]);
    }

    // ===== Subscription Plan Management =====

    public function createPlan(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            /** 0 = tidak memakai durasi tetap (mis. paket Starter gratis). */
            'duration_days' => 'required|integer|min:0|max:3650',
            'max_products' => 'nullable|integer|min:1',
            'max_customers' => 'nullable|integer|min:1',
            'max_stores' => 'nullable|integer|min:1|max:255',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'nullable|integer',
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ]);

        SubscriptionPlan::create(array_merge(
            $request->all(),
            ['billing_cycle' => $request->input('billing_cycle', 'monthly')]
        ));

        return redirect()->back()->with('success', 'Paket subscription berhasil dibuat.');
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            /** 0 = tidak memakai durasi tetap (mis. paket Starter gratis). */
            'duration_days' => 'required|integer|min:0|max:3650',
            'max_products' => 'nullable|integer|min:1',
            'max_customers' => 'nullable|integer|min:1',
            'max_stores' => 'nullable|integer|min:1|max:255',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'nullable|integer',
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ]);

        $plan->update(array_merge(
            $request->all(),
            ['billing_cycle' => $request->input('billing_cycle', $plan->billing_cycle ?? 'monthly')]
        ));

        return redirect()->back()->with('success', 'Paket subscription berhasil diperbarui.');
    }

    public function deletePlan(SubscriptionPlan $plan)
    {
        $usedCount = SubscriptionPayment::where('subscription_plan_id', $plan->id)->count();
        if ($usedCount > 0) {
            return redirect()->back()->with('error', 'Paket tidak dapat dihapus karena sudah digunakan.');
        }

        $plan->delete();

        return redirect()->back()->with('success', 'Paket subscription berhasil dihapus.');
    }

    // ===== Payment Confirmation =====

    public function confirmPayment(Request $request, SubscriptionPayment $payment)
    {
        if (! $payment->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran ini tidak dalam status menunggu konfirmasi.',
            ], 400);
        }

        $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($payment, $request) {
            $payment->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'confirmed_by' => auth()->id(),
                'admin_notes' => $request->admin_notes,
            ]);

            if ($payment->voucher_id) {
                Voucher::whereKey($payment->voucher_id)->increment('used_count');
            }

            $store = $payment->store;

            // Handle outlet add-on payment
            if ($payment->type === 'outlet_addon') {
                OutletAddOnService::applyConfirmedAddOn($payment);
                $store->is_active = true;
                $store->subscription_notes = "Add-on {$payment->extra_outlets_count} outlet dikonfirmasi via #{$payment->invoice_number}";
                $store->save();

                return;
            }

            // Handle Absensi + Payroll add-on payment
            if ($payment->type === 'attendance_payroll_addon') {
                AttendancePayrollAddOnService::applyConfirmedAddOn($payment);
                $store->is_active = true;
                $store->subscription_notes = "Paket Absensi + Payroll dikonfirmasi via #{$payment->invoice_number}";
                $store->save();

                return;
            }

            // Regular subscription payment — extend subscription
            $store->extendSubscription($payment->duration_days);
            $store->is_active = true;
            $store->subscription_notes = "Dikonfirmasi via pembayaran #{$payment->invoice_number}";
            if ($payment->subscription_plan_id) {
                $store->subscription_plan_id = $payment->subscription_plan_id;
            }
            $store->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Pembayaran berhasil dikonfirmasi dan masa aktif toko diperpanjang.',
        ]);
    }

    public function rejectPayment(Request $request, SubscriptionPayment $payment)
    {
        if (! $payment->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran ini tidak dalam status menunggu konfirmasi.',
            ], 400);
        }

        $request->validate([
            'admin_notes' => 'required|string|max:500',
        ]);

        $payment->update([
            'status' => 'rejected',
            'admin_notes' => $request->admin_notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pembayaran berhasil ditolak.',
        ]);
    }

    public function allPayments()
    {
        $payments = SubscriptionPayment::with(['store', 'user', 'plan', 'confirmedBy'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('SuperAdmin/Payments', [
            'payments' => $payments,
        ]);
    }

    // ===== Voucher Management (existing) =====

    public function createVoucher(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:vouchers,code|max:50',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'days' => 'required|integer|min:1|max:365',
            'price' => 'required|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ]);

        Voucher::create($request->all());

        return redirect()->back()->with('success', 'Voucher berhasil dibuat.');
    }

    public function updateVoucher(Request $request, Voucher $voucher)
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:vouchers,code,'.$voucher->id,
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'days' => 'required|integer|min:1|max:365',
            'price' => 'required|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
            'is_active' => 'boolean',
        ]);

        $voucher->update($request->all());

        return redirect()->back()->with('success', 'Voucher berhasil diperbarui.');
    }

    public function deleteVoucher(Voucher $voucher)
    {
        if ($voucher->used_count > 0) {
            return redirect()->back()->with('error', 'Voucher tidak dapat dihapus karena sudah digunakan.');
        }

        $voucher->delete();

        return redirect()->back()->with('success', 'Voucher berhasil dihapus.');
    }

    public function useVoucher(Request $request, Store $store)
    {
        $request->validate([
            'voucher_code' => 'required|string|exists:vouchers,code',
        ]);

        $voucher = Voucher::where('code', $request->voucher_code)->first();

        if (! $voucher->canBeUsed()) {
            return redirect()->back()->with('error', 'Voucher tidak dapat digunakan.');
        }

        DB::transaction(function () use ($voucher, $store) {
            $store->extendSubscription($voucher->days);

            VoucherUsage::create([
                'voucher_id' => $voucher->id,
                'store_id' => $store->id,
                'user_id' => auth()->id(),
                'used_at' => now(),
                'days_added' => $voucher->days,
                'amount_paid' => $voucher->price,
                'notes' => "Voucher {$voucher->code} digunakan",
            ]);

            $voucher->increment('used_count');
        });

        return redirect()->back()->with('success', "Voucher berhasil digunakan. Masa aktif toko diperpanjang {$voucher->days} hari.");
    }

    public function voucherUsages()
    {
        $usages = VoucherUsage::with(['voucher', 'store', 'user'])
            ->orderBy('used_at', 'desc')
            ->paginate(20);

        return Inertia::render('SuperAdmin/VoucherUsages', [
            'usages' => $usages,
        ]);
    }
}
