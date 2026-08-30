plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.kasirumkm.idea"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.kasirumkm.idea"
        minSdk = 23
        targetSdk = 36
        versionCode = 11
        versionName = "1.1.0"
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
        debug {
            isMinifyEnabled = false
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation("androidx.appcompat:appcompat:1.6.1")
    implementation("androidx.core:core-ktx:1.12.0")
    implementation("com.google.android.material:material:1.11.0")
    // Android Photo Picker (PickVisualMedia) untuk pemilihan gambar tanpa izin READ_MEDIA
    implementation("androidx.activity:activity-ktx:1.9.3")
    // AndroidX WebKit untuk kontrol Service Worker (mode offline PWA)
    implementation("androidx.webkit:webkit:1.11.0")
}
