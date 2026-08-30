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
        <SidebarGroupLabel class="px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4 mt-8">PLATFORM</SidebarGroupLabel>
        <SidebarMenu class="space-y-1">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton 
                    as-child 
                    :is-active="urlIsActive(item.href, page.url)" 
                    :tooltip="item.title"
                    class="h-12 px-2 rounded-2xl transition-all duration-300 group hover:bg-slate-50 relative overflow-hidden"
                >
                    <Link 
                        :href="item.href"
                        class="flex items-center w-full"
                    >
                        <!-- Active Background Shadow (Optional visual polish) -->
                        <div 
                            v-if="urlIsActive(item.href, page.url)"
                            class="absolute inset-0 bg-primary/10 rounded-2xl"
                        ></div>

                        <component 
                            :is="item.icon" 
                            class="size-5 transition-transform duration-300 group-hover:scale-110 ml-2 z-10" 
                            :class="urlIsActive(item.href, page.url) ? 'text-primary' : 'text-slate-400 group-hover:text-slate-800'"
                        />
                        <span 
                            class="ml-4 font-bold text-sm tracking-tight z-10 transition-colors duration-300"
                            :class="urlIsActive(item.href, page.url) ? 'text-primary' : 'text-slate-500 group-hover:text-slate-800'"
                        >
                            {{ item.title }}
                        </span>

                        <!-- Active Indicator (4px Side Border) -->
                        <div 
                            v-if="urlIsActive(item.href, page.url)"
                            class="absolute left-0 top-2 bottom-2 w-1 bg-primary rounded-r-full shadow-[2px_0_8px_rgba(124,58,237,0.4)] z-20"
                        ></div>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
