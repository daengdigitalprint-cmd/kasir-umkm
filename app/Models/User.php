<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'business_type',
        'password',
        'store_id',
        'role',
        'is_active',
        'is_super_admin',
        'cashier_pin_hash',
        'cashier_pin_set_at',
        'can_login_pos',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'can_login_pos' => 'boolean',
            'cashier_pin_set_at' => 'datetime',
        ];
    }

    public function hasCashierPin(): bool
    {
        return ! empty($this->cashier_pin_hash);
    }

    public function verifyCashierPin(string $pin): bool
    {
        if (! $this->hasCashierPin()) {
            return false;
        }

        return \Illuminate\Support\Facades\Hash::check($pin, $this->cashier_pin_hash);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /** Semua toko yang dapat diakses pengguna (multi-outlet). */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function cashShifts()
    {
        return $this->hasMany(CashShift::class);
    }

    public function isAdmin()
    {
        return $this->getRoleForActiveStore() === 'admin';
    }

    public function isManager()
    {
        return $this->getRoleForActiveStore() === 'manager';
    }

    public function isCashier()
    {
        return $this->getRoleForActiveStore() === 'cashier';
    }

    public function isWarehouseStaff(): bool
    {
        return $this->getRoleForActiveStore() === 'warehouse';
    }

    public function isKitchenStaff(): bool
    {
        return $this->getRoleForActiveStore() === 'kitchen';
    }

    public function isOwner(): bool
    {
        $activeRole = $this->getRoleForActiveStore();
        if ($activeRole === 'owner') {
            return true;
        }

        // Cek apakah user punya role owner di salah satu store-nya (pivot table)
        return $this->stores()->wherePivot('role', 'owner')->exists();
    }

    /** Owner — akses panel owner (monitoring, laporan lintas outlet). */
    public function canAccessOwnerPanel(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->isOwner();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /** Admin toko atau manajer — laporan, diskon, pelanggan, dan modul “komersial” penuh. */
    public function canAccessBackOffice(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->isAdmin() || $this->isManager() || $this->isOwner();
    }

    /** Admin gudang + manajer + admin — stok, produk, pembelian, transfer (Fase F3). */
    public function canAccessInventory(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->isAdmin() || $this->isManager() || $this->isWarehouseStaff() || $this->isOwner();
    }

    /** Menu Penyesuaian stok — peran inventory + kasir. */
    public function canAccessStockAdjustment(): bool
    {
        return $this->canAccessInventory() || $this->isCashier();
    }

    /** Kasir, manajer, admin, super admin, owner — POS & shift (bukan admin gudang). */
    public function canUsePointOfSale(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->isAdmin() || $this->isManager() || $this->isCashier() || $this->isOwner();
    }

    /** Hanya admin toko (pemilik) — pengaturan toko, PPN, data sensitif. */
    public function canManageStoreSettings(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->isAdmin();
    }

    /** Daftar menu_key yang diizinkan untuk role user ini. */
    public function allowedMenus(): array
    {
        // region debug-point 1
        \Log::info("User {$this->id} allowedMenus called", [
            'user_id' => $this->id,
            'user_role' => $this->role,
            'store_id' => $this->store_id,
            'method' => 'allowedMenus',
        ]);
        // endregion debug-point 1

        if ($this->isSuperAdmin()) {
            return RoleMenuPermission::distinct()->pluck('menu_key')->all();
        }

        // Gunakan role dari store yang sedang aktif jika ada
        $effectiveRole = $this->getRoleForActiveStore();

        // region debug-point 2
        \Log::info("User {$this->id} effective role", [
            'effective_role' => $effectiveRole,
            'global_role' => $this->role,
            'store_id' => $this->store_id,
        ]);
        // endregion debug-point 2

        $menus = RoleMenuPermission::allowedMenus($effectiveRole);

        // region debug-point 3
        \Log::info("User {$this->id} allowed menus", [
            'role' => $effectiveRole,
            'menu_count' => count($menus),
            'menus' => $menus,
        ]);
        // endregion debug-point 3

        return $menus;
    }

    /**
     * Dapatkan role user untuk store yang sedang aktif.
     * Jika user punya store aktif (store_id), ambil role dari pivot table.
     * Jika tidak, gunakan role global dari kolom users.role.
     */
    public function getRoleForActiveStore(): string
    {
        // region debug-point 4
        \Log::info("User {$this->id} getRoleForActiveStore called", [
            'user_id' => $this->id,
            'global_role' => $this->role,
            'store_id' => $this->store_id,
        ]);
        // endregion debug-point 4

        $storeQuery = $this->stores();
        if ($this->store_id) {
            $storeQuery->where('stores.id', $this->store_id);
        }
        $store = $storeQuery->first();

        if ($store) {
            // region debug-point 5
            \Log::info("User {$this->id} pivot check", [
                'store_id' => $this->store_id,
                'store_found' => $store ? true : false,
                'has_pivot' => $store && $store->pivot ? true : false,
                'pivot_role' => $store && $store->pivot ? $store->pivot->role : null,
            ]);
            // endregion debug-point 5

            if ($store && $store->pivot && $store->pivot->role) {
                return $store->pivot->role;
            }
        }

        // Fallback ke role global
        // region debug-point 6
        \Log::info("User {$this->id} using fallback role", [
            'fallback_role' => $this->role,
        ]);
        // endregion debug-point 6

        return $this->role ?? 'cashier';
    }

    /** Cek apakah user boleh akses menu_key tertentu. */
    public function canAccessMenu(string $menuKey): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($menuKey, $this->allowedMenus(), true);
    }

    public function isMemberOfStore(Store|int $store): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $storeId = $store instanceof Store ? $store->id : $store;

        return $this->stores()->where('stores.id', $storeId)->exists();
    }

    /**
     * Berikan akses owner ke semua outlet dalam satu organization (grup usaha).
     * Digunakan saat role owner di-assign: user otomatis dapat mengelola semua outlet grup.
     */
    public function attachAsOwnerToAllStoresInOrganization(Store $store): void
    {
        if (! $store->organization_id) {
            return;
        }

        $storeIds = $store->organization->stores()->pluck('stores.id')->all();

        if (empty($storeIds)) {
            return;
        }

        // updateOrInsert memastikan role selalu 'owner' (termasuk menimpa pivot yang sudah ada)
        foreach ($storeIds as $storeId) {
            \Illuminate\Support\Facades\DB::table('store_user')->updateOrInsert(
                [
                    'store_id' => $storeId,
                    'user_id' => $this->id,
                ],
                [
                    'role' => 'owner',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Cek apakah user boleh akses log aktivitas.
     * Hanya admin dan owner yang bisa melihat log.
     */
    public function canAccessActivityLogs(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $role = $this->getRoleForActiveStore();

        return in_array($role, ['admin', 'owner'], true);
    }
}
