<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Percent, Settings } from 'lucide-vue-next';
import { computed } from 'vue';

interface Store {
    id: number;
    name: string;
    tax_enabled: boolean;
    tax_rate: number;
    tax_name: string;
}

interface Props {
    store: Store;
    isOpen: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm({
    tax_enabled: props.store.tax_enabled ?? false,
    tax_rate: props.store.tax_rate ?? 11.00,
    tax_name: props.store.tax_name ?? 'PPN',
});

const formattedTaxRate = computed({
    get: () => form.tax_rate !== null && form.tax_rate !== undefined ? formatCurrency(form.tax_rate) : '',
    set: (value: string) => {
        const numericValue = value.replace(/[^\d]/g, '');
        form.tax_rate = numericValue ? parseFloat(numericValue) : 0;
    }
});

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(value);
};

const submit = () => {
    form.put('/settings/tax', {
        onSuccess: () => {
            emit('close');
        },
        onError: (errors) => {
            console.log('Validation errors:', errors);
        }
    });
};

const handleClose = () => {
    if (!form.processing) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="isOpen" @update:open="handleClose">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <div class="p-2 bg-blue-600 rounded-lg">
                        <Percent class="h-5 w-5 text-white" />
                    </div>
                    Pengaturan PPN
                </DialogTitle>
                <DialogDescription>
                    Kelola pengaturan pajak pertambahan nilai untuk toko Anda
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Enable/Disable Tax -->
                <div class="flex items-center space-x-2">
                    <Checkbox
                        id="tax_enabled"
                        v-model:checked="form.tax_enabled"
                    />
                    <Label for="tax_enabled" class="text-sm font-medium">
                        Aktifkan PPN
                    </Label>
                </div>

                <!-- Tax Name -->
                <div class="space-y-2">
                    <Label for="tax_name">Nama Pajak</Label>
                    <Input
                        id="tax_name"
                        v-model="form.tax_name"
                        placeholder="PPN"
                        :disabled="!form.tax_enabled"
                    />
                </div>

                <!-- Tax Rate -->
                <div class="space-y-2">
                    <Label for="tax_rate">Tarif Pajak (%)</Label>
                    <Input
                        id="tax_rate"
                        v-model="formattedTaxRate"
                        type="text"
                        placeholder="11"
                        :disabled="!form.tax_enabled"
                    />
                </div>

                <!-- Preview -->
                <div v-if="form.tax_enabled" class="p-4 bg-blue-50 rounded-lg">
                    <h4 class="font-medium text-blue-900 mb-2">Preview PPN</h4>
                    <div class="text-sm text-blue-700">
                        <p><strong>Nama:</strong> {{ form.tax_name }}</p>
                        <p><strong>Tarif:</strong> {{ form.tax_rate }}%</p>
                        <p class="text-xs mt-2 text-blue-600">
                            PPN akan diterapkan pada semua transaksi penjualan
                        </p>
                    </div>
                </div>

                <!-- Footer -->
                <DialogFooter class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="handleClose"
                        :disabled="form.processing"
                    >
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-blue-600 hover:bg-blue-700"
                    >
                        {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
