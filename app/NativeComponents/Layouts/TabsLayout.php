<?php

namespace App\NativeComponents\Layouts;

use App\Icons\Android;
use App\Icons\Ios;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

class TabsLayout extends NativeLayout
{
    /**
     * Mengaktifkan chrome native (TopBar & BottomNav bawaan platform).
     */
    public function usesNativeChrome(): bool
    {
        return true;
    }

    /**
     * Konfigurasi TopBar dinamis mengikuti judul layar aktif.
     */
    public function navBar(NativeComponent $screen): ?NavBar
    {
        $title = $screen->navTitle();

        if ($title === '') {
            $title = 'Kasir UMKM by MaccaPOS';
        }

        return NavBar::make()
            ->title($title);
    }

    /**
     * Konfigurasi BottomNav dengan 4 tab utama (Kasir, Produk, Laporan, Lainnya).
     */
    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link(
                label: 'Kasir',
                url: '/app',
                ios: Ios::Cart,
                android: Android::PointOfSale,
            ))
            ->add(Tab::link(
                label: 'Produk',
                url: '/app/produk',
                ios: Ios::Storefront,
                android: Android::Inventory,
            ))
            ->add(Tab::link(
                label: 'Laporan',
                url: '/app/laporan',
                ios: Ios::ChartBar,
                android: Android::QueryStats,
            ))
            ->add(Tab::link(
                label: 'Lainnya',
                url: '/app/lainnya',
                ios: Ios::Ellipsis,
                android: Android::Menu,
            ));
    }
}
