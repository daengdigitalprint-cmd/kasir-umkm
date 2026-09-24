<template>
    <div class="h-screen bg-white">
        <AiAssistantSidebar is-open fullscreen @close="goBack" />
    </div>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import AiAssistantSidebar from '@/components/ai/AiAssistantSidebar.vue';

function goBack() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        router.visit('/dashboard');
    }
}
</script>
