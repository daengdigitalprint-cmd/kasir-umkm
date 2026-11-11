<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class RoleMenuPermission extends Model
{
    protected $fillable = [
        'role',
        'menu_key',
    ];

    /** Daftar role yang menu-nya di-cache (untuk invalidasi massal). */
    private const CACHED_ROLES = ['admin', 'manager', 'cashier', 'warehouse', 'owner', 'kitchen', 'barber', '*all*'];

    protected static function booted(): void
    {
        // Invalidasi cache setiap kali hak akses menu berubah lewat Eloquent.
        static::saved(fn () => static::flushMenuCache());
        static::deleted(fn () => static::flushMenuCache());
    }

    private static function cacheKey(string $role): string
    {
        return 'role_menu_permissions:'.$role;
    }

    /** Hapus cache menu semua role. Panggil juga setelah delete/insert massal (query builder). */
    public static function flushMenuCache(): void
    {
        foreach (self::CACHED_ROLES as $role) {
            Cache::memo()->forget(self::cacheKey($role));
        }
    }

    /**
     * Dapatkan semua menu_key yang diizinkan untuk role tertentu.
     * Di-cache 10 menit karena dibaca di setiap request (shared props Inertia).
     */
    public static function allowedMenus(string $role): array
    {
        return Cache::memo()->remember(
            self::cacheKey($role),
            now()->addMinutes(10),
            fn () => static::where('role', $role)->pluck('menu_key')->all()
        );
    }

    /** Semua menu_key yang terdaftar (untuk super admin). */
    public static function allMenuKeys(): array
    {
        return Cache::memo()->remember(
            self::cacheKey('*all*'),
            now()->addMinutes(10),
            fn () => static::distinct()->pluck('menu_key')->all()
        );
    }

    /**
     * Dapatkan semua role yang punya akses ke menu_key tertentu.
     */
    public static function rolesWithAccess(string $menuKey): array
    {
        return static::where('menu_key', $menuKey)->pluck('role')->all();
    }
}
