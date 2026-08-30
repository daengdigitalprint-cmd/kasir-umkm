<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { resolveProfileImageSrc } from '@/lib/profileAvatar';
import type { User } from '@/types';

interface Props {
    user: User;
    showEmail?: boolean;
    showName?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
    showName: true
});

const { getInitials } = useInitials();
</script>

<template>
    <Avatar class="h-9 w-9 overflow-hidden rounded-2xl border border-white shadow-sm">
        <AvatarImage :src="resolveProfileImageSrc(user.avatar)" :alt="user.name" />
        <AvatarFallback class="rounded-2xl text-primary font-bold bg-primary/10">
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div v-if="showName" class="grid flex-1 text-left text-sm leading-tight ml-2">
        <span class="truncate font-bold text-slate-800">{{ user.name }}</span>
        <span v-if="showEmail" class="truncate text-[10px] uppercase font-black tracking-widest text-slate-400 mt-0.5">{{ user.email }}</span>
    </div>
</template>
