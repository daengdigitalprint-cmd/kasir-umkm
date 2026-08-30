<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleMenuPermission extends Model
{
    protected $fillable = [
        'role',
        'menu_key',
    ];

    /**
     * Dapatkan semua menu_key yang diizinkan untuk role tertentu.
     */
    public static function allowedMenus(string $role): array
    {
        return static::where('role', $role)->pluck('menu_key')->all();
    }

    /**
     * Dapatkan semua role yang punya akses ke menu_key tertentu.
     */
    public static function rolesWithAccess(string $menuKey): array
    {
        return static::where('menu_key', $menuKey)->pluck('role')->all();
    }
}
