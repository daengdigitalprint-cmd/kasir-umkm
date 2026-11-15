import { ref, watch } from 'vue';

const PREF_KEY = 'product_form_advanced';

/** Kolom yang hanya tampil di mode lengkap; error di sini harus membuka mode lengkap. */
const ADVANCED_FIELDS = [
    'sku',
    'barcode',
    'description',
    'wholesale_price',
    'inventory_type',
    'base_unit',
    'min_stock_level',
    'brand',
    'tax_rate',
    'allows_decimal',
    'has_variants',
    'is_bundle',
    'variants_json',
    'bundles_json',
    'sale_consumptions_json',
];

function readPref(): boolean {
    try {
        return localStorage.getItem(PREF_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * Mode form produk: sederhana (kolom inti saja) atau lengkap.
 * Pilihan pengguna diingat per perangkat. `forceOpen` dipakai saat produk sudah
 * memakai fitur lanjutan (varian, paket, bahan baku) agar tidak tersembunyi.
 */
export function useProductFormMode(forceOpen: boolean, errors: () => Record<string, unknown>) {
    const showAdvanced = ref(forceOpen || readPref());

    watch(showAdvanced, (value) => {
        try {
            localStorage.setItem(PREF_KEY, value ? '1' : '0');
        } catch {
            // Penyimpanan lokal diblokir: pilihan tidak diingat, form tetap jalan.
        }
    });

    watch(
        errors,
        (current) => {
            if (ADVANCED_FIELDS.some((field) => field in current)) {
                showAdvanced.value = true;
            }
        },
        { deep: true },
    );

    return { showAdvanced };
}
