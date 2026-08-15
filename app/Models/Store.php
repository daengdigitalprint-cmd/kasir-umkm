<?php

namespace App\Models;

use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\InventoryType;
use App\Support\StoreDefaultProductCategories;
use App\Support\StoreLocationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Store extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'business_type',
        'location_type',
        'is_primary',
        'description',
        'address',
        'phone',
        'email',
        'logo',
        'receipt_logo',
        'currency',
        'timezone',
        'settings',
        'is_active',
        'tax_enabled',
        'tax_rate',
        'tax_name',
        'subscription_start',
        'subscription_end',
        'subscription_days',
        'is_subscription_active',
        'subscription_notes',
        'subscription_plan_id',
        'pro_trial_used',
        'pro_trial_active',
        'pro_trial_start',
        'pro_trial_end',
        'extra_outlets',
    ];

    protected $attributes = [
        'location_type' => StoreLocationType::OUTLET,
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'tax_enabled' => 'boolean',
        'tax_rate' => 'decimal:2',
        'subscription_start' => 'datetime',
        'subscription_end' => 'datetime',
        'is_subscription_active' => 'boolean',
        'pro_trial_used' => 'boolean',
        'pro_trial_active' => 'boolean',
        'pro_trial_start' => 'datetime',
        'pro_trial_end' => 'datetime',
        'extra_outlets' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($store) {
            if (empty($store->slug)) {
                $store->slug = Str::slug($store->name);
            }
        });

        static::created(function (Store $store) {
            StoreDefaultProductCategories::ensureForStore($store);
            ChartOfAccountsBootstrap::ensureForStore($store);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Grup usaha / pemilik beberapa outlet (Fase F1). */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Anggota terdaftar (multi-outlet); peran per toko di pivot. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Salin produk dari sumber ke toko ini.
     *
     * @param  Store   $source
     * @param  string  $copyType  'raw_material' | 'sellable' | 'all'
     * @return array{copied: int, skipped: int, errors: string[], details: array}
     */
    public function copyProductsFrom(Store $source, string $copyType = 'all'): array
    {
        // Bangun lookup duplikasi: SKU (jika ada) + nama+inventory_type sebagai fallback
        $existingSkus = $this->products()->pluck('sku')->filter()->flip()->all();
        $existingNames = $this->products()
            ->selectRaw('name, inventory_type')
            ->get()
            ->keyBy(fn ($p) => $p->name.'|'.$p->inventory_type)
            ->toArray();

        $query = $source->products()->where('is_active', true);

        $query->when($copyType === 'raw_material', fn ($q) => $q->whereIn('inventory_type', [
            InventoryType::RAW_MATERIAL,
            InventoryType::PACKAGING,
        ]));

        $query->when($copyType === 'sellable', fn ($q) => $q->whereIn('inventory_type', [
            InventoryType::SELLABLE,
            InventoryType::PRODUCTION_OUTPUT,
        ]));

        // 'all' — tidak perlu filter, ambil semua produk aktif

        $sourceProducts = $query->orderBy('name')->get();

        $copied = 0;
        $skipped = 0;
        $errors = [];
        $details = [];

        foreach ($sourceProducts as $sp) {
            $duplicateKey = $sp->name.'|'.$sp->inventory_type;
            $isDuplicate = ($sp->sku && isset($existingSkus[$sp->sku]))
                || isset($existingNames[$duplicateKey]);

            if ($isDuplicate) {
                $skipped++;
                $details[] = ['name' => $sp->name, 'sku' => $sp->sku, 'status' => 'skipped'];
                continue;
            }

            try {
                $catId = null;
                if ($sp->product_category_id) {
                    $cat = ProductCategory::query()->firstOrCreate(
                        ['store_id' => $this->id, 'name' => $sp->category],
                        ['sort_order' => 0],
                    );
                    $catId = $cat->id;
                }

                Product::query()->create([
                    'store_id' => $this->id,
                    'name' => $sp->name,
                    'sku' => $sp->sku,
                    'barcode' => $sp->barcode,
                    'description' => $sp->description,
                    'price' => 0,
                    'cost_price' => $sp->cost_price,
                    'average_cost' => $sp->average_cost,
                    'stock_quantity' => 0,
                    'min_stock_level' => $sp->min_stock_level,
                    'product_category_id' => $catId,
                    'category' => $sp->category,
                    'unit' => $sp->unit,
                    'base_unit' => $sp->base_unit,
                    'images' => $sp->images,
                    'inventory_type' => $sp->inventory_type,
                    'tax_rate' => 0,
                    'is_active' => true,
                    'track_stock' => true,
                    'has_variants' => false,
                    'is_bundle' => false,
                    'allows_decimal' => $sp->allows_decimal,
                ]);

                $copied++;
                $details[] = ['name' => $sp->name, 'sku' => $sp->sku, 'status' => 'copied'];
            } catch (\Throwable $e) {
                $errors[] = "{$sp->name}: {$e->getMessage()}";
                $details[] = ['name' => $sp->name, 'sku' => $sp->sku, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return [
            'copied' => $copied,
            'skipped' => $skipped,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function loyaltyRewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class);
    }

    public function loyaltyRedemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }

    public function loyaltyNotifications(): HasMany
    {
        return $this->hasMany(LoyaltyNotification::class);
    }

    public function loyaltyPointLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointLedger::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function chartOfAccounts(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function preOrders(): HasMany
    {
        return $this->hasMany(PreOrder::class);
    }

    public function laundryOrders(): HasMany
    {
        return $this->hasMany(LaundryOrder::class);
    }

    public function cashShifts(): HasMany
    {
        return $this->hasMany(CashShift::class);
    }

    public function voucherUsages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function trialActivationLogs(): HasMany
    {
        return $this->hasMany(TrialActivationLog::class);
    }

    public function trialNotifications(): HasMany
    {
        return $this->hasMany(TrialNotification::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    public function isSubscriptionActive(): bool
    {
        if (! $this->is_subscription_active) {
            return false;
        }

        if (! $this->subscription_end) {
            return true;
        }

        return now()->lte($this->subscription_end);
    }

    public function getDaysRemaining(): int
    {
        if (! $this->subscription_end) {
            return 0;
        }

        $days = now()->diffInDays($this->subscription_end, false);

        return max(0, $days);
    }

    public function extendSubscription(int $days): void
    {
        $now = now();

        if (! $this->subscription_start) {
            $this->subscription_start = $now;
        }

        if (! $this->subscription_end || $this->subscription_end->lt($now)) {
            $this->subscription_end = $now->addDays($days);
        } else {
            $this->subscription_end = $this->subscription_end->addDays($days);
        }

        $this->subscription_days += $days;
        $this->is_subscription_active = true;
        $this->save();
    }

    /**
     * Set masa aktif awal setelah pembuatan toko: mengikuti paket Starter (bulanan) di database, atau 30 hari jika tidak ada.
     * Menghindari subscription_end kosong yang ditampilkan sebagai "Unlimited" di UI.
     */
    public function applyInitialStarterTrialFromPlans(): void
    {
        $starter = SubscriptionPlan::query()
            ->where('slug', 'starter')
            ->where('is_active', true)
            ->orderByRaw("case when coalesce(billing_cycle, 'monthly') = 'monthly' then 0 else 1 end")
            ->orderBy('sort_order')
            ->first();

        $trialDays = $starter ? max(1, (int) $starter->duration_days) : 30;
        $now = now();

        $this->forceFill([
            'subscription_start' => $this->subscription_start ?? $now,
            'subscription_end' => $now->copy()->addDays($trialDays),
            'subscription_days' => $trialDays,
            'is_subscription_active' => true,
            'subscription_plan_id' => $starter?->id,
            'subscription_notes' => $starter
                ? 'Registrasi: trial '.$starter->name." ({$trialDays} hari)."
                : "Registrasi: trial {$trialDays} hari (paket Starter belum aktif di sistem).",
        ])->save();
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }

    public function isCentralWarehouse(): bool
    {
        return StoreLocationType::isCentralWarehouse($this);
    }

    public function isOutlet(): bool
    {
        return StoreLocationType::isOutlet($this);
    }

    public function scopeCentralWarehouse($query)
    {
        return $query->where('location_type', StoreLocationType::CENTRAL_WAREHOUSE);
    }

    public function scopeOutlet($query)
    {
        return $query->where('location_type', StoreLocationType::OUTLET);
    }

    /**
     * Jadikan outlet ini sebagai Outlet Utama di grup usahanya.
     * Hanya satu outlet utama per grup; outlet lain di grup yang sama di-reset.
     */
    public function markAsPrimary(): void
    {
        if ($this->organization_id === null) {
            $this->update(['is_primary' => true]);

            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () {
            static::query()
                ->where('organization_id', $this->organization_id)
                ->where('location_type', StoreLocationType::OUTLET)
                ->update(['is_primary' => false]);

            $this->update(['is_primary' => true]);
        });
    }
}
