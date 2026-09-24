<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { urlIsActive } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{
    items: NavItem[];
}>();

const page = usePage();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel class="mt-8 mb-4 px-4 text-[10px] font-black tracking-[0.2em] text-slate-400 uppercase">PLATFORM</SidebarGroupLabel>
        <SidebarMenu class="space-y-1">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="urlIsActive(item.href, page.url)"
                    :tooltip="item.title"
                    class="group relative h-12 overflow-hidden rounded-2xl px-2 transition-all duration-300 hover:bg-slate-50"
                >
                    <Link :href="item.href" class="flex w-full items-center">
                        <!-- Active Background Shadow (Optional visual polish) -->
                        <div v-if="urlIsActive(item.href, page.url)" class="absolute inset-0 rounded-2xl bg-primary/10"></div>

                        <component
                            :is="item.icon"
                            class="z-10 size-5 transition-transform duration-300 group-hover:scale-110"
                            :class="urlIsActive(item.href, page.url) ? 'text-primary' : 'text-slate-400 group-hover:text-slate-800'"
                        />
                        <span
                            class="z-10 ml-4 text-sm font-bold tracking-tight transition-colors duration-300"
                            :class="urlIsActive(item.href, page.url) ? 'text-primary' : 'text-slate-500 group-hover:text-slate-800'"
                        >
                            {{ item.title }}
                        </span>

                        <!-- Active Indicator (4px Side Border) -->
                        <div
                            v-if="urlIsActive(item.href, page.url)"
                            class="absolute top-2 bottom-2 left-0 z-20 w-1 rounded-r-full bg-primary shadow-[2px_0_8px_rgba(124,58,237,0.4)]"
                        ></div>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
