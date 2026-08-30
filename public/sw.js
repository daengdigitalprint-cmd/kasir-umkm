/**
 * Service worker — cache ringan, tahan deploy (tanpa hash file build).
 * Install tidak boleh gagal total jika satu aset hilang (VPS / path berbeda).
 */
const CACHE_NAME = 'umkm-kasir-v1.0.6';
const OFFLINE_URL = '/offline.html';

/**
 * App Shell URL — halaman yang di-cache saat install agar bisa dibuka
 * kembali tanpa jaringan. Untuk Inertia SPA, semua route menghasilkan
 * HTML shell yang sama, jadi /pos cukup untuk serve seluruh app.
 */
const APP_SHELL_URL = '/pos';

/**
 * Precache saat install — hanya aset publik yang tidak butuh auth.
 * APP_SHELL_URL (/pos) di-cache saat runtime (setelah user login & kunjungi halaman).
 */
const STATIC_ASSETS = [
  '/login',
  OFFLINE_URL,
  '/manifest.json',
  '/sw.js',
  '/logo.png',
  '/favicon.png',
  '/icons/icon.svg',
];

/**
 * Route navigasi yang boleh dilayani dari app shell saat offline.
 * Semua route ini menggunakan HTML shell yang sama (Inertia SPA).
 */
const APP_SHELL_ROUTES = [
  /^\/pos(\/.*)?$/,
  /^\/dashboard(\/.*)?$/,
  /^\/transactions(\/.*)?$/,
  /^\/products(\/.*)?$/,
  /^\/customers(\/.*)?$/,
  /^\/reports(\/.*)?$/,
  /^\/cash-shifts(\/.*)?$/,
  /^\/settings(\/.*)?$/,
  /^\/stores(\/.*)?$/,
];

/**
 * Cache satu per satu; gagal satu URL tidak membatalkan seluruh install.
 */
async function cacheAssetsSafely(cache) {
  await Promise.all(
    STATIC_ASSETS.map(async (url) => {
      try {
        const req = new Request(url, { credentials: 'same-origin', cache: 'reload' });
        const res = await fetch(req);
        if (res && res.ok) {
          await cache.put(url, res.clone());
        } else {
          console.warn('Service Worker: lewati cache (bukan 200):', url, res?.status);
        }
      } catch (e) {
        console.warn('Service Worker: lewati cache (gagal fetch):', url, e);
      }
    }),
  );
}

self.addEventListener('install', (event) => {
  console.log('Service Worker: Installing...');

  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => {
        console.log('Service Worker: Precache (best-effort)');
        return cacheAssetsSafely(cache);
      })
      .then(() => {
        console.log('Service Worker: Installation complete');
        return self.skipWaiting();
      })
      .catch((error) => {
        console.error('Service Worker: Installation failed', error);
        return self.skipWaiting();
      }),
  );
});

self.addEventListener('activate', (event) => {
  console.log('Service Worker: Activating...');

  event.waitUntil(
    caches
      .keys()
      .then((cacheNames) =>
        Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== CACHE_NAME) {
              console.log('Service Worker: Deleting old cache', cacheName);
              return caches.delete(cacheName);
            }
            return Promise.resolve();
          }),
        ),
      )
      .then(() => {
        console.log('Service Worker: Activation complete');
        return self.clients.claim();
      }),
  );
});

/**
 * Pola URL yang boleh di-cache (GET-only).
 * Mencakup web routes POS (/pos/*, /products/catalog) dan api/* jika ada.
 */
const API_CACHE_PATTERNS = [
  /^\/api\/products/,
  /^\/api\/customers/,
  /^\/api\/transactions/,
  /^\/api\/reports/,
  /^\/products\/catalog/,
  /^\/pos\/(products|catalog)/,
];

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  if (request.method !== 'GET') {
    return;
  }

  if (!url.protocol.startsWith('http')) {
    return;
  }

  /** Jangan intercept dev Vite / HMR (hindari ERR_FAILED di lingkungan dev). */
  if (url.pathname.includes('@vite') || url.pathname.includes('/@fs/') || url.port === '5173') {
    return;
  }

  if (isStaticAsset(request)) {
    event.respondWith(handleStaticAsset(request));
  } else if (isApiRequest(request)) {
    event.respondWith(handleApiRequest(request));
  } else if (isNavigationRequest(request)) {
    event.respondWith(handleNavigationRequest(request));
  } else {
    event.respondWith(handleOtherRequest(request));
  }
});

function isStaticAsset(request) {
  const url = new URL(request.url);
  return /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot|webp)$/i.test(url.pathname);
}

function isApiRequest(request) {
  const url = new URL(request.url);
  return url.pathname.startsWith('/api/') || API_CACHE_PATTERNS.some((pattern) => pattern.test(url.pathname));
}

function isNavigationRequest(request) {
  return request.mode === 'navigate';
}

async function handleStaticAsset(request) {
  try {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    console.error('Service Worker: Static asset fetch failed', error);
    return new Response('Asset not available offline', { status: 404 });
  }
}

async function handleApiRequest(request) {
  try {
    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    console.log('Service Worker: API request failed, trying cache', error);
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    return new Response(
      JSON.stringify({
        error: 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.',
        offline: true,
      }),
      {
        status: 503,
        headers: { 'Content-Type': 'application/json' },
      },
    );
  }
}

async function handleNavigationRequest(request) {
  const url = new URL(request.url);

  // Navigasi route aplikasi — selalu coba jaringan dulu.
  // Hindari menyajikan app shell yang stale (bisa mengandung halaman
  // yang sudah tidak valid saat session kadaluarsa → menyebabkan hydration error & WSOD).
  try {
    const networkResponse = await fetch(request);

    // Cache app shell hanya untuk navigasi pertama kali, dengan batasan:
    // - hanya cache /pos, bukan path yang mengandung query auth
    // - jangan cache redirect (302) karena berisi data session yang akan expire
    if (networkResponse.ok) {
      const isAppShellRoute = APP_SHELL_ROUTES.some((p) => p.test(url.pathname));
      if (isAppShellRoute && url.pathname === APP_SHELL_URL) {
        const cache = await caches.open(CACHE_NAME);
        cache.put(request, networkResponse.clone());
      }
    }
    return networkResponse;
  } catch (error) {
    console.log('Service Worker: Navigation request failed, looking for cached shell', url.pathname, error);

    // 1. Coba sajikan dari cache URL yang persis sama (jika pernah dikunjungi
    //    dan benar-benar offline — bukan stale redirect)
    const exactCache = await caches.match(request);
    if (exactCache && exactCache.status === 200) {
      return exactCache;
    }

    // 2. Jika termasuk app route, sajikan app shell dari cache — cukup untuk
    //    /pos saja, dan hanya jika status 200 (bukan redirect 302).
    const isAppShellRoute = APP_SHELL_ROUTES.some((p) => p.test(url.pathname));
    if (isAppShellRoute) {
      const shellCache = await caches.match(APP_SHELL_URL);
      if (shellCache && shellCache.status === 200) {
        console.log('Service Worker: Serving app shell for offline route:', url.pathname);
        return shellCache;
      }
    }

    // 3. Fallback ke /login
    const loginCache = await caches.match('/login');
    if (loginCache && loginCache.status === 200) {
      return loginCache;
    }

    // 4. Last resort: offline.html
    const offlineCache = await caches.match(OFFLINE_URL);
    if (offlineCache && offlineCache.status === 200) {
      return offlineCache;
    }

    return new Response(
      `<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline</title></head><body style="font-family:system-ui;padding:2rem;text-align:center"><p>Anda sedang offline.</p><button type="button" onclick="location.reload()">Coba lagi</button></body></html>`,
      { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
  }
}

async function handleOtherRequest(request) {
  try {
    return await fetch(request);
  } catch (error) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    /** Jangan lempar error — cegah Uncaught (in promise) di konsol. */
    console.warn('Service Worker: fetch gagal, kembalikan 503:', request.url, error);
    
    // Fallback JSON dummy untuk mencegah Inertia/Vue crash (Layar Putih)
    // Jika header menerima JSON atau Inertia, return JSON, jika tidak return Response Error generic
    const acceptHeader = request.headers.get('Accept') || '';
    if (request.headers.get('X-Inertia') === 'true' || acceptHeader.includes('application/json')) {
      return new Response(
        JSON.stringify({
            props: {
                errors: { network: 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.' }
            },
            url: request.url,
            version: null
        }),
        { 
            status: 409, // Menggunakan 409 Conflict agar trigger Inertia error modal
            headers: { 
                'Content-Type': 'application/json',
                'X-Inertia': 'true'
            } 
        }
      );
    }
    
    return new Response('', { status: 503, statusText: 'Network Unavailable' });
  }
}

self.addEventListener('sync', (event) => {
  console.log('Service Worker: Background sync triggered', event.tag);

  if (event.tag === 'background-sync') {
    event.waitUntil(doBackgroundSync());
  }
});

/**
 * Background Sync: kirim pesan ke semua tab/client agar
 * useOfflineSync.syncPending() dijalankan dari dalam halaman.
 * SW tidak bisa akses IndexedDB composable secara langsung,
 * jadi kita delegasikan ke client via postMessage.
 */
async function doBackgroundSync() {
  try {
    console.log('Service Worker: Performing background sync — notifying clients');
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: false });
    for (const client of allClients) {
      client.postMessage({ type: 'SW_BACKGROUND_SYNC' });
    }
    console.log('Service Worker: Background sync message sent to', allClients.length, 'client(s)');
  } catch (error) {
    console.error('Service Worker: Background sync failed', error);
  }
}

// Debug: respond to DEBUG_LIST_CACHE message from page
self.addEventListener('message', async (event) => {
  if (event.data?.type === 'DEBUG_LIST_CACHE') {
    const keys = await caches.keys();
    const urlsByBucket = {};
    for (const key of keys) {
      const cache = await caches.open(key);
      const reqs = await cache.keys();
      urlsByBucket[key] = reqs.map((r) => r.url);
    }
    const source = event.source;
    if (source) {
      source.postMessage({ type: 'DEBUG_CACHE_RESPONSE', data: urlsByBucket });
    }
  }
});

self.addEventListener('push', (event) => {
  const options = {
    body: event.data ? event.data.text() : 'Notifikasi dari UMKM Kasir',
    icon: '/logo.png',
    badge: '/logo.png',
    vibrate: [100, 50, 100],
    data: { dateOfArrival: Date.now(), primaryKey: 1 },
    actions: [
      { action: 'explore', title: 'Buka Aplikasi', icon: '/logo.png' },
      { action: 'close', title: 'Tutup', icon: '/logo.png' },
    ],
  };

  event.waitUntil(self.registration.showNotification('UMKM Kasir', options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  if (event.action === 'explore') {
    event.waitUntil(clients.openWindow('/login'));
  } else if (event.action !== 'close') {
    event.waitUntil(clients.openWindow('/login'));
  }
});
