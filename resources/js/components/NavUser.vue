<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from 'lucide-vue-next';
import UserMenuContent from './UserMenuContent.vue';

const page = usePage();
const user = page.props.auth.user;
const { isMobile, state } = useSidebar();
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton size="lg" class="h-16 p-3 rounded-3xl bg-slate-50/50 border border-slate-100 hover:bg-slate-100 hover:border-slate-200 transition-all duration-300 data-[state=open]:bg-primary/5 data-[state=open]:text-primary group">
                        <UserInfo :user="user" />
                        <div class="ml-2 flex flex-col items-start leading-none">
                            <span class="font-bold text-xs text-slate-800">{{ user.name }}</span>
                            <span class="text-[9px] font-black text-slate-400 tracking-widest uppercase mt-1">Admin Utama</span>
                        </div>
                        <ChevronsUpDown class="ml-auto size-4 text-slate-400 group-hover:text-primary transition-colors" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="isMobile ? 'bottom' : state === 'collapsed' ? 'left' : 'bottom'"
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
