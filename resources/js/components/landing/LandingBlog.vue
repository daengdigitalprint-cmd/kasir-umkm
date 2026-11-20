<script setup lang="ts">
/**
 * Artikel blog terbaru di beranda.
 *
 * Tujuan SEO: beranda menautkan ke artikel (dan sebaliknya), sehingga Google
 * menemukan artikel lewat tautan, bukan hanya lewat sitemap.
 *
 * Gaya sengaja TIDAK scoped: daftar <ul class="lp-blog-grid"> di HTML prerender
 * diganti server dengan artikel terbaru (LandingSeo::injectLatestArticles), dan
 * HTML buatan server itu tidak membawa atribut data-v-*.
 */
export type LandingArticle = {
    title: string;
    url: string;
    excerpt: string | null;
    date: string | null;
};

defineProps<{
    articles: LandingArticle[];
}>();
</script>

<template>
    <section v-if="articles.length" class="lp-blog pt-130" id="blog">
        <div class="mxw-1330 container">
            <div class="sec-title text-center mb-50">
                <h2 class="title">Tips Kasir, Stok &amp; Keuangan untuk UMKM</h2>
                <p class="lp-blog-sub">Panduan praktis dari blog Kasir UMKM by MaccaPOS untuk usaha yang lebih rapi.</p>
            </div>
            <ul class="lp-blog-grid">
                <li v-for="article in articles" :key="article.url" class="lp-blog-card">
                    <a :href="article.url" class="lp-blog-link">
                        <span v-if="article.date" class="lp-blog-date">{{ article.date }}</span>
                        <h3 class="lp-blog-title">{{ article.title }}</h3>
                        <p v-if="article.excerpt" class="lp-blog-excerpt">{{ article.excerpt }}</p>
                        <span class="lp-blog-more">Baca artikel &rarr;</span>
                    </a>
                </li>
            </ul>
            <div class="lp-blog-all">
                <a href="/blog" class="thm-btn fintech-btn">Lihat semua artikel</a>
            </div>
        </div>
    </section>
</template>

<style>
.lp-blog-sub {
    margin-top: 12px;
    color: #4b5563;
}
.lp-blog-grid {
    list-style: none;
    margin: 0;
    padding: 0 20px;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
}
.lp-blog-card {
    margin: 0;
}
.lp-blog-link {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 28px;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    background: #fff;
    color: inherit;
    text-decoration: none;
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}
.lp-blog-link:hover {
    border-color: #0891b2;
    box-shadow: 0 10px 30px rgba(8, 145, 178, 0.12);
}
.lp-blog-date {
    font-size: 14px;
    color: #6b7280;
}
.lp-blog-title {
    margin: 8px 0 12px;
    font-size: 20px;
    line-height: 1.35;
    color: #111827;
}
.lp-blog-excerpt {
    margin: 0 0 16px;
    color: #4b5563;
    font-size: 15px;
    line-height: 1.6;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.lp-blog-more {
    margin-top: auto;
    font-weight: 600;
    color: #0891b2;
}
.lp-blog-all {
    margin-top: 40px;
    text-align: center;
}
@media (max-width: 991px) {
    .lp-blog-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 575px) {
    .lp-blog-grid {
        grid-template-columns: 1fr;
        padding: 0;
    }
    .lp-blog-link {
        padding: 22px;
    }
}
</style>
