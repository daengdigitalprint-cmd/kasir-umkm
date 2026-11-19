<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsShell from '@/components/settings/SettingsShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toastSuccess } from '@/lib/swal';
import { update as PasswordController } from '@/routes/password';
import { Form } from '@inertiajs/vue3';
</script>

<template>
    <SettingsShell active="password" title="Password" description="Pakai password yang panjang dan tidak dipakai di tempat lain.">
        <SettingsCard>
            <Form
                v-bind="PasswordController.form()"
                :options="{ preserveScroll: true }"
                reset-on-success
                :reset-on-error="['password', 'password_confirmation', 'current_password']"
                class="max-w-md space-y-4"
                v-slot="{ errors, processing }"
                @success="toastSuccess('Password diperbarui')"
            >
                <div class="space-y-1.5">
                    <Label for="current_password">Password sekarang</Label>
                    <Input id="current_password" name="current_password" type="password" class="h-11" autocomplete="current-password" />
                    <InputError :message="errors.current_password" />
                </div>
                <div class="space-y-1.5">
                    <Label for="password">Password baru</Label>
                    <Input id="password" name="password" type="password" class="h-11" autocomplete="new-password" />
                    <InputError :message="errors.password" />
                </div>
                <div class="space-y-1.5">
                    <Label for="password_confirmation">Ulangi password baru</Label>
                    <Input id="password_confirmation" name="password_confirmation" type="password" class="h-11" autocomplete="new-password" />
                    <InputError :message="errors.password_confirmation" />
                </div>
                <Button :disabled="processing">{{ processing ? 'Menyimpan…' : 'Simpan password' }}</Button>
            </Form>
        </SettingsCard>
    </SettingsShell>
</template>
