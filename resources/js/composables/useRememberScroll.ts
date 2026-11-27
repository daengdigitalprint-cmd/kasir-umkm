import { onBeforeUnmount, watch, type Ref } from 'vue';

/**
 * Mengingat posisi gulir sebuah wadah (mis. menu samping) lintas perpindahan halaman.
 *
 * Tiap halaman memasang layout-nya sendiri, jadi layout dibuat ulang setiap pindah halaman dan
 * wadah yang bisa digulir kembali ke atas. Posisi terakhir disimpan di memori (untuk navigasi
 * Inertia) dan di sessionStorage (untuk muat ulang penuh), lalu dipulihkan saat wadah muncul lagi.
 */
const ingatan = new Map<string, number>();

const kunci = (nama: string) => `kumkm:gulir:${nama}`;

/** Selama jendela ini isi wadah masih boleh bertambah tinggi (menu baru selesai tersusun). */
const JENDELA_PULIH_MS = 1500;
const TANDA_PENGGUNA = ['wheel', 'touchstart', 'pointerdown', 'keydown'] as const;

function baca(nama: string): number {
    if (ingatan.has(nama)) {
        return ingatan.get(nama)!;
    }
    try {
        const v = Number(window.sessionStorage.getItem(kunci(nama)));
        return Number.isFinite(v) && v > 0 ? v : 0;
    } catch {
        return 0;
    }
}

function simpan(nama: string, posisi: number) {
    ingatan.set(nama, posisi);
    try {
        window.sessionStorage.setItem(kunci(nama), String(Math.round(posisi)));
    } catch {
        // Penyimpanan peramban diblokir: cukup pakai ingatan di memori.
    }
}

export function useRememberScroll(el: Ref<HTMLElement | null>, nama: string) {
    let terpasang: HTMLElement | null = null;
    let memulihkan = false;
    let pengamat: ResizeObserver | null = null;
    let pewaktu: ReturnType<typeof setTimeout> | undefined;

    const saatGulir = () => {
        // Saat memulihkan, posisi bisa terpotong karena isi belum setinggi biasanya: jangan disimpan.
        if (terpasang && !memulihkan) {
            simpan(nama, terpasang.scrollTop);
        }
    };

    const akhiriPemulihan = () => {
        memulihkan = false;
        pengamat?.disconnect();
        pengamat = null;
        clearTimeout(pewaktu);
        if (terpasang) {
            for (const t of TANDA_PENGGUNA) {
                terpasang.removeEventListener(t, akhiriPemulihan);
            }
        }
    };

    const lepas = () => {
        akhiriPemulihan();
        terpasang?.removeEventListener('scroll', saatGulir);
        terpasang = null;
    };

    // Wadah bisa baru muncul setelah layout terpasang (mis. sidebar desktop menunggu cek lebar layar).
    watch(
        el,
        (baru) => {
            lepas();
            if (!baru) {
                return;
            }
            terpasang = baru;
            baru.addEventListener('scroll', saatGulir, { passive: true });

            const sasaran = baca(nama);
            if (sasaran <= 0) {
                return;
            }
            const terapkan = () => {
                if (memulihkan && terpasang) {
                    terpasang.scrollTop = sasaran;
                }
            };
            memulihkan = true;
            terapkan();
            // Isi yang bertambah tinggi sesaat setelah tampil: terapkan lagi sampai posisinya tercapai.
            if (typeof ResizeObserver !== 'undefined') {
                pengamat = new ResizeObserver(terapkan);
                pengamat.observe(baru);
                for (const anak of Array.from(baru.children)) {
                    pengamat.observe(anak);
                }
            }
            // Begitu pengguna sendiri menyentuh menu, pemulihan berhenti agar tidak melawan gulirannya.
            for (const t of TANDA_PENGGUNA) {
                baru.addEventListener(t, akhiriPemulihan, { passive: true });
            }
            pewaktu = setTimeout(akhiriPemulihan, JENDELA_PULIH_MS);
        },
        { immediate: true, flush: 'post' },
    );

    onBeforeUnmount(lepas);
}
