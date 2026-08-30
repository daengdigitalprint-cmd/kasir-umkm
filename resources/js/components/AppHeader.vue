<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { resolveProfileImageSrc } from '@/lib/profileAvatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { NavigationMenu, NavigationMenuItem, NavigationMenuList, navigationMenuTriggerStyle } from '@/components/ui/navigation-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import { toUrl, urlIsActive } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, NavItem } from '@/types';
import { InertiaLinkProps, Link, usePage } from '@inertiajs/vue3';
import { BookOpen, Folder, LayoutGrid, Menu, Search } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItem[];
}

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);

const isCurrentRoute = computed(() => (url: NonNullable<InertiaLinkProps['href']>) => urlIsActive(url, page.url));

const activeItemStyles = computed(
    () => (url: NonNullable<InertiaLinkProps['href']>) =>
        isCurrentRoute.value(toUrl(url)) ? 'text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100' : '',
);

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const rightNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <div>
        <div class="sticky top-0 z-50 transition-all duration-300 glass dark:glass-dark border-b">
            <div class="mx-auto flex h-16 items-center px-4 md:max-w-7xl">
                <!-- Mobile Menu -->
                <div class="lg:hidden">
                    <Sheet>
                        <SheetTrigger :as-child="true">
                            <Button variant="ghost" size="icon" class="mr-2 h-9 w-9 rounded-xl btn-premium">
                                <Menu class="h-5 w-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" class="w-[300px] p-6 glass dark:glass-dark border-r">
                            <SheetTitle class="sr-only">Navigation Menu</SheetTitle>
                            <SheetHeader class="flex justify-start text-left mb-6">
                                <AppLogoIcon class="size-8 fill-current text-primary" />
                            </SheetHeader>
                            <div class="flex h-full flex-1 flex-col justify-between space-y-4 py-2">
                                <nav class="-mx-3 space-y-2">
                                    <Link
                                        v-for="item in mainNavItems"
                                        :key="item.title"
                                        :href="item.href"
                                        class="flex items-center gap-x-3 rounded-2xl px-4 py-3 text-sm font-semibold transition-all duration-300 hover:bg-accent/10 group"
                                        :class="isCurrentRoute(item.href) ? 'bg-primary/5 text-primary' : 'text-slate-600 dark:text-slate-400'"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="h-5 w-5 group-hover:scale-110 transition-transform" />
                                        {{ item.title }}
                                    </Link>
                                </nav>
                                <div class="flex flex-col space-y-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                                    <a
                                        v-for="item in rightNavItems"
                                        :key="item.title"
                                        :href="toUrl(item.href)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="flex items-center space-x-2 text-sm font-medium text-slate-500 hover:text-primary transition-colors"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="h-4 w-4" />
                                        <span>{{ item.title }}</span>
                                    </a>
                                </div>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>

                <Link :href="dashboard()" class="flex items-center gap-x-2 group">
                    <AppLogo class="group-hover:opacity-80 transition-opacity" />
                </Link>

                <!-- Desktop Menu -->
                <div class="hidden h-full lg:flex lg:flex-1">
                    <NavigationMenu class="ml-10 flex h-full items-stretch">
                        <NavigationMenuList class="flex h-full items-stretch space-x-4">
                            <NavigationMenuItem v-for="(item, index) in mainNavItems" :key="index" class="relative flex h-full items-center">
                                <Link
                                    :class="[
                                        navigationMenuTriggerStyle(), 
                                        isCurrentRoute(item.href) ? 'text-primary' : 'text-slate-500', 
                                        'h-10 cursor-pointer px-4 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-900 transition-all font-semibold'
                                    ]"
                                    :href="item.href"
                                >
                                    <component v-if="item.icon" :is="item.icon" class="mr-2 h-4 w-4" />
                                    {{ item.title }}
                                </Link>
                                <div
                                    v-if="isCurrentRoute(item.href)"
                                    class="absolute bottom-0 left-1/2 h-1 w-8 -translate-x-1/2 translate-y-px rounded-full bg-primary shadow-glow shadow-primary/40"
                                ></div>
                            </NavigationMenuItem>
                        </NavigationMenuList>
                    </NavigationMenu>
                </div>

                <div class="ml-auto flex items-center space-x-3">
                    <div class="relative flex items-center space-x-2">
                        <Button variant="ghost" size="icon" class="group h-10 w-10 cursor-pointer rounded-xl btn-premium bg-slate-50/50 dark:bg-slate-900/50 border border-transparent hover:border-slate-200 dark:hover:border-slate-800">
                            <Search class="size-5 opacity-60 group-hover:opacity-100 transition-opacity" />
                        </Button>

                        <div class="hidden space-x-2 lg:flex">
                            <template v-for="item in rightNavItems" :key="item.title">
                                <TooltipProvider :delay-duration="0">
                                    <Tooltip>
                                        <TooltipTrigger>
                                            <Button variant="ghost" size="icon" as-child class="group h-10 w-10 cursor-pointer rounded-xl btn-premium">
                                                <a :href="toUrl(item.href)" target="_blank" rel="noopener noreferrer">
                                                    <span class="sr-only">{{ item.title }}</span>
                                                    <component :is="item.icon" class="size-5 opacity-60 group-hover:opacity-100" />
                                                </a>
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent side="bottom" class="rounded-xl glass dark:glass-dark shadow-xl">
                                            <p class="font-medium text-xs">{{ item.title }}</p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </template>
                        </div>
                    </div>

                    <div class="h-6 w-px bg-slate-200 dark:bg-slate-800 mx-1"></div>

                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-10 rounded-full p-0.5 btn-premium ring-offset-2 focus-within:ring-2 focus-within:ring-primary shadow-sm"
                            >
                                <Avatar class="size-9 overflow-hidden rounded-full border border-slate-100 dark:border-slate-800">
                                    <AvatarImage
                                        :src="resolveProfileImageSrc(auth.user.avatar)"
                                        :alt="auth.user.name"
                                    />
                                    <AvatarFallback class="rounded-full bg-primary/10 font-bold text-primary dark:bg-primary/20">
                                        {{ getInitials(auth.user?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64 p-2 rounded-2xl glass dark:glass-dark shadow-2xl border-slate-200 dark:border-slate-800">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div v-if="props.breadcrumbs.length > 1" class="flex w-full glass dark:glass-dark border-b">
            <div class="mx-auto flex h-10 w-full items-center justify-start px-4 text-slate-500 md:max-w-7xl">
                <Breadcrumbs :breadcrumbs="breadcrumbs" class="text-xs font-medium" />
            </div>
        </div>
    </div>
</template>
