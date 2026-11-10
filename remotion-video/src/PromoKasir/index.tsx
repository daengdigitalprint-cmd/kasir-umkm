import React from "react";
import { AbsoluteFill, Series } from "remotion";
import { DesktopScene, Hook, Outro, PhoneScene, Voice } from "./Scenes";
import { COLORS, SCENES } from "./theme";

export type PromoKasirProps = {
  /** Matikan bila berkas di public/vo/ belum dibuat. */
  voiceover: boolean;
};

/**
 * Video promosi 60 detik, 1080x1920.
 * Urutannya: masalah → kasir → stok → dashboard → laporan → ajakan bertindak.
 */
export const PromoKasir: React.FC<PromoKasirProps> = ({ voiceover }) => {
  return (
    <AbsoluteFill style={{ background: COLORS.ink }}>
      <Series>
        <Series.Sequence durationInFrames={SCENES.hook}>
          <Hook />
          <Voice name="hook" enabled={voiceover} />
        </Series.Sequence>

        <Series.Sequence durationInFrames={SCENES.pos}>
          <PhoneScene
            shot="pos"
            kicker="Kasir"
            title="Transaksi selesai dalam hitungan detik"
            bullets={["Scan barcode atau cari nama", "Tunai, QRIS, e-wallet", "Struk langsung tercetak"]}
            from="#4c1d95"
            to="#7943e5"
          />
          <Voice name="pos" enabled={voiceover} />
        </Series.Sequence>

        <Series.Sequence durationInFrames={SCENES.stock}>
          <PhoneScene
            shot="products"
            kicker="Produk & Stok"
            title="Produk jual dan bahan baku dalam satu tempat"
            bullets={["Stok berkurang otomatis", "Peringatan saat menipis", "Impor massal dari Excel"]}
            from="#065f46"
            to="#10b981"
          />
          <Voice name="stock" enabled={voiceover} />
        </Series.Sequence>

        <Series.Sequence durationInFrames={SCENES.dashboard}>
          <PhoneScene
            shot="dashboard"
            kicker="Dashboard"
            title="Lihat omzet toko dari mana saja"
            bullets={["Penjualan hari ini seketika", "Grafik tren tiap bulan", "Produk terlaris terbaca"]}
            from="#1e3a8a"
            to="#3b82f6"
          />
          <Voice name="dashboard" enabled={voiceover} />
        </Series.Sequence>

        <Series.Sequence durationInFrames={SCENES.reports}>
          <DesktopScene
            shot="reports"
            kicker="Laporan"
            title="Laporan siap pakai, bukan tebak-tebakan"
            bullets={["Laba rugi otomatis", "Rekap per kasir & shift", "Ekspor ke Excel dan PDF"]}
            from="#7c2d12"
            to="#f59e0b"
          />
          <Voice name="reports" enabled={voiceover} />
        </Series.Sequence>

        <Series.Sequence durationInFrames={SCENES.outro}>
          <Outro />
          <Voice name="outro" enabled={voiceover} />
        </Series.Sequence>
      </Series>
    </AbsoluteFill>
  );
};
