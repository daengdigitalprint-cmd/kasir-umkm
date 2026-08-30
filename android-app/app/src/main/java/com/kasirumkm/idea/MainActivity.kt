package com.kasirumkm.idea

import android.annotation.SuppressLint
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import android.os.Bundle
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.OnBackPressedCallback
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.edit
import androidx.core.graphics.createBitmap
import androidx.core.net.toUri
import androidx.webkit.ServiceWorkerClientCompat
import androidx.webkit.ServiceWorkerControllerCompat
import androidx.webkit.WebViewFeature

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var btBridge: BluetoothPrintBridge
    private lateinit var permissionHandler: PermissionHandler

    private var filePathCallback: ValueCallback<Array<Uri>>? = null

    // Cegah loop reload saat offline: hanya sekali coba muat ulang dari cache.
    private var offlineReloadAttempted = false

    private val fileChooserLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val results = WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data)
        filePathCallback?.onReceiveValue(results)
        filePathCallback = null
    }

    private val photoPickerLauncher = registerForActivityResult(
        ActivityResultContracts.PickMultipleVisualMedia(5)
    ) { uris ->
        filePathCallback?.onReceiveValue(if (uris.isNotEmpty()) uris.toTypedArray() else null)
        filePathCallback = null
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)
        permissionHandler = PermissionHandler(this)
        btBridge = BluetoothPrintBridge(this, webView, permissionHandler)

        setupWebView()
        setupServiceWorker()
        setupBackNavigation()
        requestBluetoothPermissions()
    }

    private fun setupWebView() {
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = true
            mediaPlaybackRequiresUserGesture = false
            javaScriptCanOpenWindowsAutomatically = true
            loadWithOverviewMode = true
            useWideViewPort = true
            setGeolocationEnabled(true)
            mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
            // Gunakan cache saat jaringan tidak tersedia agar app shell PWA tetap tampil offline
            cacheMode = WebSettings.LOAD_DEFAULT
        }

        webView.addJavascriptInterface(btBridge, "BluetoothPrint")

        webView.webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: android.webkit.PermissionRequest?) {
                this@MainActivity.runOnUiThread {
                    val requestedResources = request?.resources ?: arrayOf()
                    if (permissionHandler.hasCameraPermission()) {
                        request?.grant(requestedResources)
                    } else {
                        permissionHandler.requestCameraPermission(
                            onGranted = { request?.grant(requestedResources) },
                            onDenied = { request?.deny() }
                        )
                    }
                }
            }

            override fun onGeolocationPermissionsShowPrompt(
                origin: String?,
                callback: android.webkit.GeolocationPermissions.Callback?
            ) {
                if (permissionHandler.hasLocationPermission()) {
                    callback?.invoke(origin, true, false)
                } else {
                    permissionHandler.requestLocationPermission(
                        onGranted = { callback?.invoke(origin, true, false) },
                        onDenied = { callback?.invoke(origin, false, false) }
                    )
                }
            }

            override fun onShowFileChooser(
                webView: WebView?,
                filePathCallback: ValueCallback<Array<Uri>>?,
                fileChooserParams: FileChooserParams?
            ): Boolean {
                this@MainActivity.filePathCallback?.onReceiveValue(null)
                this@MainActivity.filePathCallback = filePathCallback

                try {
                    if (acceptsImagesOnly(fileChooserParams)) {
                        photoPickerLauncher.launch(
                            PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)
                        )
                    } else {
                        val intent = fileChooserParams?.createIntent()
                        if (intent != null) {
                            fileChooserLauncher.launch(intent)
                        } else {
                            this@MainActivity.filePathCallback = null
                            return false
                        }
                    }
                } catch (e: Exception) {
                    this@MainActivity.filePathCallback = null
                    return false
                }
                return true
            }

            override fun getDefaultVideoPoster(): Bitmap? {
                return createBitmap(1, 1, Bitmap.Config.ARGB_8888)
            }
        }

        webView.webViewClient = object : WebViewClient() {
            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                super.onPageStarted(view, url, favicon)
                if (url != null && isLandingPage(url)) {
                    view?.stopLoading()
                    view?.loadUrl(getString(R.string.kasir_url))
                }
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)

                // Halaman berhasil dimuat: reset guard & kembalikan cache mode default.
                offlineReloadAttempted = false
                view?.settings?.cacheMode = WebSettings.LOAD_DEFAULT

                if (url != null && isLandingPage(url)) {
                    view?.loadUrl(getString(R.string.kasir_url))
                    return
                }

                webView.evaluateJavascript(
                    "window.dispatchEvent(new Event('androidBridgeReady'))",
                    null,
                )

                // Intercept Inertia SPA navigation ke landing page
                // Inertia tidak trigger shouldOverrideUrlLoading karena navigasi via AJAX
                val loginUrl = getString(R.string.kasir_url)
                webView.evaluateJavascript(
                    """(function() {
                        if (window._androidNavGuardInstalled) return;
                        window._androidNavGuardInstalled = true;

                        // Monitor perubahan URL via Inertia router event
                        document.addEventListener('inertia:navigate', function(event) {
                            var url = event.detail && event.detail.page && event.detail.page.url;
                            if (url === '/' || url === '') {
                                window.location.href = '$loginUrl';
                            }
                        });

                        // Fallback: monitor popstate & pushState/replaceState
                        var origPush = history.pushState;
                        var origReplace = history.replaceState;
                        function checkPath() {
                            if (window.location.pathname === '/' || window.location.pathname === '') {
                                window.location.href = '$loginUrl';
                            }
                        }
                        history.pushState = function() {
                            origPush.apply(this, arguments);
                            checkPath();
                        };
                        history.replaceState = function() {
                            origReplace.apply(this, arguments);
                            checkPath();
                        };
                        window.addEventListener('popstate', checkPath);
                    })()""".trimIndent(),
                    null
                )

                // Sembunyikan kontrol video (player) secara paksa lewat CSS/JS injection yang lebih agresif
                webView.evaluateJavascript(
                    "(function() { " +
                        "var style = document.createElement('style');" +
                        "style.innerHTML = 'video::-webkit-media-controls { display:none !important; } " +
                        "video::-webkit-media-controls-enclosure { display:none !important; } " +
                        "video::-webkit-media-controls-panel { display:none !important; } " +
                        "video::-webkit-media-controls-play-button { display:none !important; } " +
                        "video::-webkit-media-controls-start-playback-button { display:none !important; } " +
                        "video { pointer-events: none !important; }'; " +
                        "document.head.appendChild(style);" +
                        "function fixVideo(v) { " +
                        "    v.controls = false; " +
                        "    v.setAttribute('playsinline', 'true'); " +
                        "    v.setAttribute('webkit-playsinline', 'true'); " +
                        "    v.setAttribute('autoplay', 'true'); " +
                        "    v.muted = true; " +
                        "    v.play().catch(function(e){}); " +
                        "} " +
                        "document.querySelectorAll('video').forEach(fixVideo); " +
                        "var observer = new MutationObserver(function(mutations) { " +
                        "    mutations.forEach(function(mutation) { " +
                        "        mutation.addedNodes.forEach(function(node) { " +
                        "            if (node.tagName === 'VIDEO') fixVideo(node); " +
                        "            else if (node.querySelectorAll) node.querySelectorAll('video').forEach(fixVideo); " +
                        "        }); " +
                        "    }); " +
                        "}); " +
                        "observer.observe(document.documentElement, { childList: true, subtree: true }); " +
                        "})();",
                    null
                )
            }

            override fun shouldOverrideUrlLoading(
                view: WebView?,
                request: WebResourceRequest?,
            ): Boolean {
                val url = request?.url?.toString() ?: return false
                if (isLandingPage(url)) {
                    view?.loadUrl(getString(R.string.kasir_url))
                    return true
                }
                // Buka URL WhatsApp (wa.me / wa.link) lewat Intent eksternal sehingga
                // teralihkan ke aplikasi WhatsApp / browser. Jangan dimuat di dalam WebView.
                if (isWhatsAppLink(url)) {
                    try {
                        val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        startActivity(intent)
                    } catch (e: Exception) {
                        view?.loadUrl(url)
                    }
                    return true
                }
                return false
            }

            private fun isLandingPage(url: String): Boolean {
                val uri = url.toUri()
                val path = uri.path ?: ""
                return (uri.host == "kasirumkm.web.id" || uri.host == "www.kasirumkm.web.id") &&
                    (path == "/" || path == "" || path == "/index.php")
            }

            private fun isWhatsAppLink(url: String): Boolean {
                val uri = url.toUri()
                val host = uri.host ?: ""
                return host == "wa.me" || host.endsWith("wa.me") ||
                    host == "wa.link" || host.endsWith("wa.link") ||
                    host == "api.whatsapp.com" || host.endsWith("api.whatsapp.com")
            }

            override fun onReceivedError(
                view: WebView?,
                request: WebResourceRequest?,
                error: WebResourceError?,
            ) {
                super.onReceivedError(view, request, error)
                // Hanya tangani kegagalan pada main frame (navigasi halaman utama).
                if (request?.isForMainFrame != true) return
                // Cegah loop: hanya coba muat ulang dari cache satu kali.
                if (offlineReloadAttempted) return
                offlineReloadAttempted = true
                // Saat offline, paksa WebView menyajikan halaman dari cache HTTP-nya
                // sehingga app shell PWA + Service Worker dapat mengambil alih.
                view?.settings?.cacheMode = WebSettings.LOAD_CACHE_ELSE_NETWORK
                request.url?.toString()?.let { view?.loadUrl(it) }
            }
        }

        webView.loadUrl(getString(R.string.kasir_url))
    }

    /**
     * Mengaktifkan Service Worker pada WebView agar strategi caching PWA (sw.js)
     * berjalan untuk sub-resource saat offline. Tanpa ini, Service Worker tidak
     * pernah dijalankan oleh WebView sehingga mode offline gagal.
     */
    private fun setupServiceWorker() {
        if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_BASIC_USAGE)) {
            val swController = ServiceWorkerControllerCompat.getInstance()
            swController.setServiceWorkerClient(object : ServiceWorkerClientCompat() {
                override fun shouldInterceptRequest(request: WebResourceRequest): WebResourceResponse? {
                    return null
                }
            })

            val swSettings = swController.serviceWorkerWebSettings

            if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_CONTENT_ACCESS)) {
                swSettings.allowContentAccess = true
            }
            if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_FILE_ACCESS)) {
                swSettings.allowFileAccess = true
            }
            if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_CACHE_MODE)) {
                swSettings.cacheMode = WebSettings.LOAD_DEFAULT
            }
        }
    }

    /**
     * Mengecek apakah input file di web hanya menerima gambar (accept image only).
     * Jika ya, kita bisa memakai Android Photo Picker tanpa izin READ_MEDIA.
     */
    private fun acceptsImagesOnly(params: WebChromeClient.FileChooserParams?): Boolean {
        val types = params?.acceptTypes ?: return false
        val filtered = types.filter { it.isNotBlank() }
        if (filtered.isEmpty()) return false
        return filtered.all { it.startsWith("image/") || it == ".jpg" || it == ".jpeg" || it == ".png" || it == ".gif" || it == ".webp" }
    }

    private fun setupBackNavigation() {
        onBackPressedDispatcher.addCallback(
            this,
            object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() {
                    if (webView.canGoBack()) {
                        webView.goBack()
                    } else {
                        isEnabled = false
                        onBackPressedDispatcher.onBackPressed()
                    }
                }
            },
        )
    }

    private fun requestBluetoothPermissions() {
        permissionHandler.requestBluetoothPermissions(
            onGranted = {
                // Cek apakah popup aktivasi Bluetooth sudah pernah ditampilkan sebelumnya
                val prefs = getSharedPreferences("app_prefs", MODE_PRIVATE)
                val isPromptShown = prefs.getBoolean("bt_prompt_shown", false)

                if (!isPromptShown) {
                    permissionHandler.promptEnableBluetooth(this)
                    // Tandai bahwa popup sudah ditampilkan sekali
                    prefs.edit {
                        putBoolean("bt_prompt_shown", true)
                    }
                }

                requestCameraPermission()
            },
            onDenied = {
                // Vue.js akan menampilkan peringatan lewat UI jika izin ditolak
            },
        )
    }

    private fun requestCameraPermission() {
        permissionHandler.requestCameraPermission(
            onGranted = { /* sudah granted */ },
            onDenied = { /* user denied */ },
        )
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray,
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        permissionHandler.onRequestPermissionsResult(requestCode, grantResults)
    }
}
