import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
}

export type AppPageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    abilities?: {
        backoffice?: boolean;
        inventory?: boolean;
        pos?: boolean;
        manage_store_settings?: boolean;
    };
    accessible_stores?: Array<{ id: number; name: string; slug: string }>;
    sidebarOpen: boolean;
    flash?: {
        success?: string | null;
        error?: string | null;
        warning?: string | null;
        info?: string | null;
        status?: string | null;
        show_product_modal?: boolean | null;
    };
    subscription?: {
        is_active: boolean;
        days_remaining: number | null;
        subscription_end: string | null;
        starter_free: boolean;
        status: 'inactive' | 'active' | 'unlimited' | 'expired' | 'expiring';
        has_pending_payment: boolean;
    };
    plan_limits?: {
        plan_name: string | null;
        plan_slug: string | null;
        max_products: number | null;
        max_customers: number | null;
        products_count: number;
        customers_count: number;
        allows_advanced_retail: boolean;
    };
};

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    store_id?: number | null;
    role?: 'admin' | 'manager' | 'cashier' | 'warehouse' | string;
    is_super_admin?: boolean;
}

export type BreadcrumbItemType = BreadcrumbItem;
