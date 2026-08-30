<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    LoaderCircle,
    Mail,
    Lock,
    Eye,
    EyeOff,
    ArrowRight,
    ShieldCheck,
    UserPlus,
} from 'lucide-vue-next';
import { onMounted, onUnmounted, ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const showPassword = ref(false);
const togglePasswordVisibility = () => {
    showPassword.value = !showPassword.value;
};

const buyerAssetsBase = '/buyer-assets';
const maskotUrl = '/logo-macca.png';

/** Muat skrip plugin template (butuh bootstrap.bundle.min untuk form & show/hide). */
const templateScripts: string[] = [
    '/buyer-assets/js/jquery-3.7.1.min.js',
    '/buyer-assets/js/bootstrap.bundle.min.js',
    '/buyer-assets/js/wow.min.js',
];
const loadedScripts: HTMLScriptElement[] = [];

function loadScriptsSequentially(urls: string[]): Promise<void> {
    return urls.reduce<Promise<void>>((acc, url) => {
        return acc.then(
            () =>
                new Promise<void>((resolve) => {
                    const existing = document.querySelector<HTMLScriptElement>(
                        `script[data-lp-auth-src="${url}"]`,
                    );
                    if (existing) {
                        resolve();
                        return;
                    }
                    const s = document.createElement('script');
                    s.src = url;
                    s.async = false;
                    s.defer = true;
                    s.dataset.lpAuthSrc = url;
                    s.onload = () => resolve();
                    s.onerror = () => resolve();
                    document.body.appendChild(s);
                    loadedScripts.push(s);
                }),
        );
    }, Promise.resolve());
}

onMounted(() => {
    document.documentElement.classList.add('lp-landing');
    document.body.classList.add('lp-landing', 'body-bg-2');
    void loadScriptsSequentially(templateScripts);
});

onUnmounted(() => {
    document.documentElement.classList.remove('lp-landing');
    document.body.classList.remove('lp-landing', 'body-bg-2');
    loadedScripts.forEach((s) => {
        if (s.parentNode) {
            s.parentNode.removeChild(s);
        }
    });
    loadedScripts.length = 0;
});
</script>

<template>
    <Head title="Masuk - UMKM Kasir">
        <link
            rel="stylesheet"
            :href="`${buyerAssetsBase}/css/bootstrap.min.css`"
        />
        <link rel="stylesheet" :href="`${buyerAssetsBase}/css/all.min.css`" />
        <link rel="stylesheet" :href="`${buyerAssetsBase}/css/animate.css`" />
        <link rel="stylesheet" :href="`${buyerAssetsBase}/css/meanmenu.css`" />
        <link rel="stylesheet" :href="`${buyerAssetsBase}/css/nice-select.css`" />
        <link rel="stylesheet" :href="`${buyerAssetsBase}/css/flaticon.css`" />
        <link rel="stylesheet" :href="`${buyerAssetsBase}/css/main.css`" />
    </Head>

    <div id="smooth-wrapper">
        <div id="smooth-content" class="lp-auth-page">
            <div class="ellipse-1">
                <img :src="`${buyerAssetsBase}/img/home-2/ellipse-1.png`" alt="img" />
            </div>
            <div class="ellipse-2">
                <img :src="`${buyerAssetsBase}/img/home-2/ellipse-2.png`" alt="img" />
            </div>
            <div class="container-fluid lp-auth-wrapper">
                <div class="row g-0 lp-auth-row">
                    <!-- Panel kanan: form -->
                    <div class="col-12 lp-auth-form-wrap">
                        <div class="lp-auth-form">
                            <div class="lp-auth-mobile-logo d-lg-none mb-4 text-center">
                                <Link href="/">
                                    <img :src="maskotUrl" alt="UMKM Kasir" class="lp-auth-mobile-logo-img" />
                                </Link>
                            </div>

                            <div class="gt-section-title style-3 mb-4">
                                <h6 class="tt-capitalize wow fadeInUp">Selamat datang kembali</h6>
                                <h2 class="char-animation">Masuk ke akun Anda</h2>
                            </div>

                            <div
                                v-if="status"
                                class="lp-auth-status wow fadeInUp"
                                data-wow-delay=".2s"
                            >
                                {{ status }}
                            </div>

                            <Form
                                method="post"
                                action="/login"
                                :reset-on-success="['password']"
                                v-slot="{ errors, processing }"
                                class="lp-auth-form-fields wow fadeInUp"
                                data-wow-delay=".3s"
                            >
                                <div class="lp-auth-field">
                                    <Label for="email" class="lp-auth-label">Email</Label>
                                    <div class="lp-auth-input">
                                        <Mail class="lp-auth-input-icon" />
                                        <Input
                                            id="email"
                                            type="email"
                                            name="email"
                                            required
                                            autofocus
                                            :tabindex="1"
                                            autocomplete="email"
                                            placeholder="nama@tokoanda.com"
                                            class="lp-auth-input-control !pl-14 !pr-14"
                                        />
                                    </div>
                                    <InputError :message="errors.email" />
                                </div>

                                <div class="lp-auth-field">
                                    <div class="lp-auth-label-row">
                                        <Label for="password" class="lp-auth-label">Kata sandi</Label>
                                        <Link
                                            v-if="canResetPassword"
                                            href="/forgot-password"
                                            class="lp-auth-link"
                                            :tabindex="5"
                                        >
                                            Lupa password?
                                        </Link>
                                    </div>
                                    <div class="lp-auth-input">
                                        <Lock class="lp-auth-input-icon" />
                                        <Input
                                            id="password"
                                            :type="showPassword ? 'text' : 'password'"
                                            name="password"
                                            required
                                            :tabindex="2"
                                            autocomplete="current-password"
                                            placeholder="••••••••"
                                            class="lp-auth-input-control !pl-14 !pr-14"
                                        />
                                        <button
                                            type="button"
                                            @click="togglePasswordVisibility"
                                            class="lp-auth-input-toggle"
                                            aria-label="Tampilkan atau sembunyikan kata sandi"
                                        >
                                            <Eye v-if="!showPassword" />
                                            <EyeOff v-else />
                                        </button>
                                    </div>
                                    <InputError :message="errors.password" />
                                </div>

                                <Label for="remember" class="lp-auth-checkbox">
                                    <Checkbox id="remember" name="remember" :tabindex="3" />
                                    <span>Ingat saya di perangkat ini</span>
                                </Label>

                                <button
                                    type="submit"
                                    :tabindex="4"
                                    :disabled="processing"
                                    class="gt-theme-btn style-3 bg-theme lp-auth-submit"
                                >
                                    <LoaderCircle v-if="processing" class="lp-auth-spinner" />
                                    <template v-if="!processing">
                                        <span>Masuk</span>
                                        <ArrowRight />
                                    </template>
                                    <span v-else>Memverifikasi...</span>
                                </button>
                            </Form>

                            <div class="lp-auth-divider">
                                <span>Belum punya akun?</span>
                            </div>

                            <Link href="/register" class="gt-theme-btn style-3 bg-border lp-auth-alt">
                                <UserPlus />
                                Buat akun baru
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.lp-auth-page {
    min-height: 100vh;
    background-image: url("/buyer-assets/img/home-2/feature-bg.jpg");
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    color: #05111A;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    position: relative;
    overflow: hidden;
    z-index: 1;
}

.ellipse-1 {
    position: absolute;
    bottom: -20%;
    z-index: -1;
    left: 0;
    pointer-events: none;
}
.ellipse-2 {
    position: absolute;
    top: -20%;
    z-index: -1;
    right: 0;
    pointer-events: none;
}

.lp-auth-wrapper {
    min-height: 100vh;
    position: relative;
    z-index: 2;
}

.lp-auth-row {
    min-height: 100vh;
}

/* === Form panel === */
.lp-auth-form-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 56px 24px;
    background: transparent;
}

.lp-auth-form {
    width: 100%;
    max-width: 480px;
    margin: 0 auto;
    background: #ffffff;
    padding: 40px;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(5, 17, 26, 0.05);
    border: 1px solid rgba(160, 234, 113, 0.15);
}

@media (max-width: 575px) {
    .lp-auth-form {
        padding: 24px 18px;
        border-radius: 16px;
    }
}

.lp-auth-mobile-logo-img {
    height: 64px;
    width: auto;
}

.lp-auth-form .gt-section-title.style-3 h6 {
    color: #A0EA71;
    font-size: 0.78rem;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    font-weight: 800;
    margin: 0 0 8px;
}
.lp-auth-form .gt-section-title.style-3 h2 {
    font-size: clamp(1.5rem, 2.4vw, 2rem);
    font-weight: 800;
    color: #173401;
    margin: 0;
    line-height: 1.2;
}

.lp-auth-status {
    margin-bottom: 24px;
    padding: 12px 16px;
    background: rgba(160, 234, 113, 0.1);
    border-left: 3px solid #A0EA71;
    border-radius: 8px;
    color: #173401;
    font-weight: 600;
    font-size: 0.85rem;
}

.lp-auth-form-fields {
    display: flex;
    flex-direction: column;
    gap: 18px;
    margin-top: 28px;
}

.lp-auth-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.lp-auth-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.lp-auth-label {
    font-size: 0.7rem;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.12em;
}

.lp-auth-link {
    font-size: 0.75rem;
    font-weight: 700;
    color: #14b8a6;
    text-decoration: none;
}
.lp-auth-link:hover {
    text-decoration: underline;
}

.lp-auth-input {
    position: relative;
}
.lp-auth-input-icon,
.lp-auth-input :deep(.lp-auth-input-icon),
.lp-auth-input :deep(svg.lp-auth-input-icon) {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    height: 20px !important;
    width: 20px !important;
    max-height: 20px;
    max-width: 20px;
    min-height: 20px;
    min-width: 20px;
    padding: 0 !important;
    margin: 0 !important;
    color: #A0EA71;
    z-index: 3;
    pointer-events: none;
    flex-shrink: 0;
}
.lp-auth-input :deep(.lp-auth-input-control) {
    height: 52px !important;
    width: 100%;
    border: 1.5px solid rgba(160, 234, 113, 0.3);
    background: #ffffff;
    color: #05111A;
    border-radius: 14px !important;
    padding: 0 56px 0 56px !important;
    font-size: 0.95rem;
    font-weight: 500;
    box-shadow: 0 6px 18px -12px rgba(5, 17, 26, 0.15);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    text-indent: 0;
    margin: 0;
}
.lp-auth-input :deep(.lp-auth-input-control::placeholder) {
    color: #9BA0A3;
    font-weight: 500;
    padding-left: 0;
}
.lp-auth-input :deep(.lp-auth-input-control:focus) {
    outline: none;
    border-color: #A0EA71;
    box-shadow: 0 0 0 4px rgba(160, 234, 113, 0.2);
}
.lp-auth-input-toggle {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 0;
    color: #9BA0A3;
    cursor: pointer;
    border-radius: 999px;
    transition: color 0.15s ease, background-color 0.15s ease;
    z-index: 3;
}
.lp-auth-input-toggle:hover {
    color: #A0EA71;
    background: rgba(160, 234, 113, 0.1);
}
.lp-auth-input-toggle :deep(svg),
.lp-auth-input-toggle svg {
    height: 18px !important;
    width: 18px !important;
    max-height: 18px;
    max-width: 18px;
    padding: 0 !important;
    margin: 0 !important;
}

.lp-auth-checkbox {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 0.85rem;
    color: #686866;
    font-weight: 600;
}

.lp-auth-submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    height: 52px;
    border-radius: 14px;
    font-weight: 800;
    font-size: 0.95rem;
    color: #05111A;
    text-decoration: none;
    background: #A0EA71;
    border: 2px solid #A0EA71;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    margin-top: 6px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.lp-auth-submit:hover:not(:disabled) {
    background: #173401;
    border-color: #173401;
    color: #A0EA71;
}
.lp-auth-submit:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}
.lp-auth-submit svg {
    height: 18px;
    width: 18px;
}
.lp-auth-spinner {
    height: 18px;
    width: 18px;
    animation: lp-auth-spin 0.9s linear infinite;
}
@keyframes lp-auth-spin {
    to { transform: rotate(360deg); }
}

.lp-auth-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 28px 0 18px;
    color: #9BA0A3;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
}
.lp-auth-divider::before,
.lp-auth-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: rgba(155, 160, 163, 0.35);
}

.lp-auth-alt {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    height: 52px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 0.95rem;
    color: #05111A;
    background: #f57c00;
    border: 2px solid #f57c00;
    text-decoration: none;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.lp-auth-alt:hover {
    background: #05111A;
    border-color: #05111A;
    color: #f57c00;
}
.lp-auth-alt svg {
    height: 18px;
    width: 18px;
}

@media (max-width: 991.98px) {
    .lp-auth-form-wrap {
        padding: 32px 20px;
    }
    .lp-auth-form {
        max-width: 100%;
    }
}
</style>
