<script setup lang="ts">
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsShell from '@/components/settings/SettingsShell.vue';
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';

const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', icon: Sun, label: 'Terang' },
    { value: 'dark', icon: Moon, label: 'Gelap' },
    { value: 'system', icon: Monitor, label: 'Ikuti perangkat' },
] as const;
</script>

<template>
    <SettingsShell active="tampilan" title="Tampilan" description="Berlaku untuk perangkat yang sedang Anda pakai.">
        <SettingsCard title="Tema">
            <div class="grid max-w-lg gap-3 sm:grid-cols-3">
                <button
                    v-for="o in options"
                    :key="o.value"
                    type="button"
                    :aria-pressed="appearance === o.value"
                    class="flex flex-col items-center gap-2 rounded-xl border px-3 py-4 text-sm font-semibold transition"
                    :class="
                        appearance === o.value ? 'border-primary bg-primary/5 text-primary' : 'border-slate-200 text-slate-600 hover:border-slate-300'
                    "
                    @click="updateAppearance(o.value)"
                >
                    <component :is="o.icon" class="h-5 w-5" />
                    {{ o.label }}
                </button>
            </div>
        </SettingsCard>
    </SettingsShell>
</template>
