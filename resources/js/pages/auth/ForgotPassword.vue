<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Inbox, KeyRound, LoaderCircle, Mail, MailCheck, RotateCw } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Email tujuan setelah tautan dikirim; kosong = tampilkan form. */
        sentTo?: string | null;
        /** Jeda minimal sebelum boleh kirim ulang (detik), sama dengan throttle broker. */
        resendSeconds?: number;
        expireMinutes?: number;
    }>(),
    { sentTo: null, resendSeconds: 60, expireMinutes: 60 },
);

const buyerAssetsBase = '/buyer-assets';
const maskotUrl = '/logo-kasir-umkm.png?v=2';

/** Tampilkan form lagi (ganti email) walau sudah ada email terkirim. */
const editing = ref(false);
const showSent = computed(() => Boolean(props.sentTo) && !editing.value);

// Hitung mundur tombol "Kirim ulang".
const secondsLeft = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;
function startCountdown() {
    secondsLeft.value = props.resendSeconds;
    if (timer) clearInterval(timer);
    timer = setInterval(() => {
        secondsLeft.value = Math.max(0, secondsLeft.value - 1);
        if (secondsLeft.value === 0 && timer) {
            clearInterval(timer);
            timer = null;
        }
    }, 1000);
}

/** Setelah form terkirim (termasuk kirim ulang ke email yang sama). */
function onSent() {
    editing.value = false;
    startCountdown();
}

watch(
    () => props.sentTo,
    (val) => {
        if (val) {
            editing.value = false;
            startCountdown();
        }
    },
);

onMounted(() => {
    document.documentElement.classList.add('lp-landing');
    document.body.classList.add('lp-landing', 'body-bg-2');
    if (props.sentTo) startCountdown();
});

onUnmounted(() => {
    document.documentElement.classList.remove('lp-landing');
    document.body.classList.remove('lp-landing', 'body-bg-2');
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head title="Lupa kata sandi - Kasir UMKM by MaccaPOS">
        <link rel="stylesheet" :href="`${buyerAssetsBase}/css/bootstrap.min.css`" />
        <link rel="stylesheet" :href="`${buyerAssetsBase}/css/main.css`" />
    </Head>

    <div class="lp-auth-page">
        <div class="ellipse-1">
            <img :src="`${buyerAssetsBase}/img/home-2/ellipse-1.png`" alt="" />
        </div>
        <div class="ellipse-2">
            <img :src="`${buyerAssetsBase}/img/home-2/ellipse-2.png`" alt="" />
        </div>

        <div class="lp-auth-form-wrap">
            <div class="lp-auth-form">
                <div class="mb-4 text-center">
                    <Link href="/">
                        <img :src="maskotUrl" alt="Kasir UMKM by MaccaPOS" class="lp-auth-logo" />
                    </Link>
                </div>

                <!-- Sudah terkirim -->
                <div v-if="showSent" class="lp-sent" role="status" aria-live="polite">
                    <div class="lp-sent-icon">
                        <MailCheck />
                    </div>
                    <h1 class="lp-title text-center">Cek email Anda</h1>
                    <p class="lp-lead text-center">
                        Bila <strong class="lp-email">{{ sentTo }}</strong> terdaftar di Kasir UMKM, tautan untuk membuat kata sandi baru sudah
                        kami kirim ke email tersebut.
                    </p>

                    <ol class="lp-steps">
                        <li>
                            <span class="lp-step-no">1</span>
                            <span>Buka email dengan judul <strong>"Atur ulang kata sandi Kasir UMKM"</strong>.</span>
                        </li>
                        <li>
                            <span class="lp-step-no">2</span>
                            <span>Tekan tombol <strong>Atur ulang kata sandi</strong> di email itu.</span>
                        </li>
                        <li>
                            <span class="lp-step-no">3</span>
                            <span>Buat kata sandi baru. Tautan berlaku <strong>{{ expireMinutes }} menit</strong>.</span>
                        </li>
                    </ol>

                    <div class="lp-tip">
                        <Inbox class="lp-tip-icon" />
                        <span>Belum masuk? Tunggu 1–2 menit, lalu cek folder <strong>Spam</strong> atau <strong>Promosi</strong>.</span>
                    </div>

                    <Form method="post" action="/forgot-password" v-slot="{ processing }" class="mt-4" @success="onSent">
                        <input type="hidden" name="email" :value="sentTo" />
                        <button type="submit" class="lp-btn lp-btn-outline" :disabled="processing || secondsLeft > 0">
                            <LoaderCircle v-if="processing" class="lp-spin" />
                            <RotateCw v-else />
                            <span v-if="secondsLeft > 0">Kirim ulang dalam {{ secondsLeft }} detik</span>
                            <span v-else>Kirim ulang tautan</span>
                        </button>
                    </Form>

                    <Link href="/login" class="lp-btn lp-btn-primary mt-3">
                        <ArrowLeft />
                        Kembali ke halaman masuk
                    </Link>

                    <p class="lp-small text-center mt-3">
                        Salah email?
                        <button type="button" class="lp-link" @click="editing = true">Ganti alamat email</button>
                    </p>
                </div>

                <!-- Form -->
                <div v-else>
                    <div class="lp-head-icon">
                        <KeyRound />
                    </div>
                    <h1 class="lp-title text-center">Lupa kata sandi?</h1>
                    <p class="lp-lead text-center">Tenang, masukkan email akun Anda. Kami kirim tautan untuk membuat kata sandi baru.</p>

                    <Form method="post" action="/forgot-password" v-slot="{ errors, processing }" class="lp-fields" @success="onSent">
                        <div class="lp-field">
                            <Label for="email" class="lp-label">Email akun</Label>
                            <div class="lp-input">
                                <Mail class="lp-input-icon" />
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autofocus
                                    autocomplete="email"
                                    inputmode="email"
                                    :default-value="sentTo ?? ''"
                                    placeholder="nama@tokoanda.com"
                                    class="lp-input-control !h-[54px] !rounded-[14px] !pl-14 !pr-4"
                                />
                            </div>
                            <InputError :message="errors.email" />
                        </div>

                        <button type="submit" :disabled="processing" class="lp-btn lp-btn-primary">
                            <LoaderCircle v-if="processing" class="lp-spin" />
                            <template v-if="!processing">
                                <span>Kirim tautan reset</span>
                                <ArrowRight />
                            </template>
                            <span v-else>Mengirim...</span>
                        </button>
                    </Form>

                    <Link href="/login" class="lp-back">
                        <ArrowLeft />
                        Kembali ke halaman masuk
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.lp-auth-page {
    min-height: 100vh;
    background-image: url('/buyer-assets/img/home-2/feature-bg.jpg');
    background-size: cover;
    background-position: center;
    color: #05111a;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    position: relative;
    overflow: hidden;
    z-index: 1;
}
.ellipse-1,
.ellipse-2 {
    position: absolute;
    z-index: -1;
    pointer-events: none;
}
.ellipse-1 {
    bottom: -20%;
    left: 0;
}
.ellipse-2 {
    top: -20%;
    right: 0;
}

.lp-auth-form-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 20px;
}
.lp-auth-form {
    width: 100%;
    max-width: 460px;
    background: #ffffff;
    padding: 36px;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(5, 17, 26, 0.06);
    border: 1px solid rgba(160, 234, 113, 0.2);
}
@media (max-width: 575px) {
    .lp-auth-form {
        padding: 24px 18px;
        border-radius: 16px;
    }
}
.lp-auth-logo {
    display: block;
    height: 56px;
    width: auto;
    margin: 0 auto;
}

.lp-head-icon,
.lp-sent-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 16px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.lp-head-icon {
    background: rgba(160, 234, 113, 0.18);
    color: #173401;
}
.lp-sent-icon {
    background: #dcfce7;
    color: #15803d;
    box-shadow: 0 0 0 8px rgba(34, 197, 94, 0.1);
}
.lp-head-icon :deep(svg),
.lp-sent-icon :deep(svg) {
    width: 30px;
    height: 30px;
}

.lp-title {
    font-size: clamp(1.4rem, 2.4vw, 1.75rem);
    font-weight: 800;
    color: #173401;
    margin: 0 0 8px;
    line-height: 1.25;
}
.lp-lead {
    font-size: 0.95rem;
    line-height: 1.55;
    color: #475569;
    margin: 0;
}
.lp-email {
    color: #173401;
    word-break: break-all;
}

.lp-fields {
    display: flex;
    flex-direction: column;
    gap: 18px;
    margin-top: 24px;
}
.lp-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.lp-label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #334155;
}
.lp-input {
    position: relative;
}
.lp-input :deep(.lp-input-icon) {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    color: #65a30d;
    z-index: 3;
    pointer-events: none;
}
.lp-input :deep(.lp-input-control) {
    height: 54px;
    width: 100%;
    border: 1.5px solid rgba(101, 163, 13, 0.35);
    background: #ffffff;
    color: #05111a;
    border-radius: 14px;
    padding: 0 16px 0 52px;
    font-size: 1rem;
    font-weight: 500;
}
.lp-input :deep(.lp-input-control:focus) {
    outline: none;
    border-color: #65a30d;
    box-shadow: 0 0 0 4px rgba(160, 234, 113, 0.25);
}

.lp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    height: 52px;
    border-radius: 14px;
    font-weight: 800;
    font-size: 0.95rem;
    text-decoration: none;
    cursor: pointer;
    transition:
        background-color 0.15s ease,
        border-color 0.15s ease,
        color 0.15s ease;
}
.lp-btn :deep(svg) {
    width: 18px;
    height: 18px;
}
.lp-btn-primary {
    color: #05111a;
    background: #a0ea71;
    border: 2px solid #a0ea71;
}
.lp-btn-primary:hover:not(:disabled) {
    background: #173401;
    border-color: #173401;
    color: #a0ea71;
}
.lp-btn-outline {
    color: #173401;
    background: #ffffff;
    border: 2px solid rgba(101, 163, 13, 0.4);
}
.lp-btn-outline:hover:not(:disabled) {
    border-color: #65a30d;
    background: rgba(160, 234, 113, 0.1);
}
.lp-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.lp-spin {
    animation: lp-spin 0.9s linear infinite;
}
@keyframes lp-spin {
    to {
        transform: rotate(360deg);
    }
}

.lp-back {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: 22px;
    font-size: 0.9rem;
    font-weight: 700;
    color: #0f766e;
    text-decoration: none;
}
.lp-back :deep(svg) {
    width: 16px;
    height: 16px;
}
.lp-back:hover {
    text-decoration: underline;
}

.lp-steps {
    list-style: none;
    padding: 0;
    margin: 22px 0 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.lp-steps li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 0.9rem;
    line-height: 1.5;
    color: #334155;
}
.lp-step-no {
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    border-radius: 999px;
    background: #173401;
    color: #a0ea71;
    font-weight: 800;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.lp-tip {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 18px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #fffbeb;
    color: #92400e;
    font-size: 0.85rem;
    line-height: 1.5;
}
.lp-tip :deep(.lp-tip-icon) {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    margin-top: 1px;
}

.lp-small {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 0;
}
.lp-link {
    background: none;
    border: 0;
    padding: 0;
    font-weight: 700;
    color: #0f766e;
    cursor: pointer;
}
.lp-link:hover {
    text-decoration: underline;
}
</style>
