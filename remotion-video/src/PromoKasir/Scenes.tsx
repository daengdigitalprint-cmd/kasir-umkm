import React from "react";
import {
  AbsoluteFill,
  Audio,
  interpolate,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { Bullets, SceneTitle } from "./Captions";
import { BrowserFrame, PhoneFrame } from "./PhoneFrame";
import { COLORS, FONT } from "./theme";

/**
 * Narasi satu adegan. Berkasnya dibuat oleh scripts/voiceover.mjs; kalau
 * belum dibuat, render dengan --props='{"voiceover":false}'.
 */
export const Voice: React.FC<{ name: string; enabled: boolean }> = ({ name, enabled }) =>
  enabled ? <Audio src={staticFile(`vo/${name}.mp3`)} /> : null;

const Backdrop: React.FC<{ from: string; to: string; children: React.ReactNode }> = ({
  from,
  to,
  children,
}) => (
  <AbsoluteFill style={{ background: `linear-gradient(165deg, ${from} 0%, ${to} 100%)` }}>
    {children}
  </AbsoluteFill>
);

/** Adegan pembuka: masalah yang dirasakan pemilik toko. */
export const Hook: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const line1 = spring({ frame, fps, config: { damping: 200 } });
  const line2 = spring({ frame: frame - 30, fps, config: { damping: 200 } });
  const line3 = spring({ frame: frame - 70, fps, config: { damping: 200 } });

  return (
    <Backdrop from={COLORS.ink} to={COLORS.primaryDeep}>
      <AbsoluteFill
        style={{
          fontFamily: FONT,
          alignItems: "center",
          justifyContent: "center",
          textAlign: "center",
          padding: "0 90px",
        }}
      >
        <div
          style={{
            fontSize: 64,
            fontWeight: 600,
            color: "rgba(255,255,255,0.72)",
            opacity: interpolate(line1, [0, 1], [0, 1]),
            transform: `translateY(${interpolate(line1, [0, 1], [40, 0])}px)`,
          }}
        >
          Masih catat penjualan
        </div>
        <div
          style={{
            fontSize: 92,
            fontWeight: 800,
            color: COLORS.white,
            marginTop: 18,
            opacity: interpolate(line2, [0, 1], [0, 1]),
            transform: `translateY(${interpolate(line2, [0, 1], [40, 0])}px)`,
          }}
        >
          di buku tulis?
        </div>
        <div
          style={{
            marginTop: 70,
            padding: "22px 48px",
            borderRadius: 999,
            background: COLORS.accent,
            color: COLORS.accentInk,
            fontSize: 44,
            fontWeight: 800,
            opacity: interpolate(line3, [0, 1], [0, 1]),
            transform: `scale(${interpolate(line3, [0, 1], [0.7, 1])})`,
          }}
        >
          Saatnya pindah ke kasir digital
        </div>
      </AbsoluteFill>
    </Backdrop>
  );
};

/** Adegan fitur dengan screenshot ponsel. */
export const PhoneScene: React.FC<{
  shot: string;
  kicker: string;
  title: string;
  bullets: string[];
  from: string;
  to: string;
}> = ({ shot, kicker, title, bullets, from, to }) => (
  <Backdrop from={from} to={to}>
    <PhoneFrame src={staticFile(`shots/${shot}.png`)} delay={6} width={470} />
    <SceneTitle kicker={kicker} title={title} />
    <Bullets items={bullets} delay={40} />
  </Backdrop>
);

/** Adegan fitur dengan screenshot desktop. */
export const DesktopScene: React.FC<{
  shot: string;
  kicker: string;
  title: string;
  bullets: string[];
  from: string;
  to: string;
}> = ({ shot, kicker, title, bullets, from, to }) => (
  <Backdrop from={from} to={to}>
    <BrowserFrame src={staticFile(`shots/${shot}.png`)} delay={6} width={1000} />
    <SceneTitle kicker={kicker} title={title} />
    <Bullets items={bullets} delay={40} />
  </Backdrop>
);

/** Penutup: ajakan bertindak. */
export const Outro: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const brand = spring({ frame, fps, config: { damping: 200 } });
  const cta = spring({ frame: frame - 35, fps, config: { damping: 200 } });
  const url = spring({ frame: frame - 65, fps, config: { damping: 200 } });

  return (
    <Backdrop from={COLORS.primary} to={COLORS.primaryDeep}>
      <AbsoluteFill
        style={{
          fontFamily: FONT,
          alignItems: "center",
          justifyContent: "center",
          textAlign: "center",
          padding: "0 80px",
        }}
      >
        <div
          style={{
            fontSize: 110,
            fontWeight: 800,
            color: COLORS.white,
            letterSpacing: -2,
            opacity: interpolate(brand, [0, 1], [0, 1]),
            transform: `scale(${interpolate(brand, [0, 1], [0.8, 1])})`,
          }}
        >
          Macca<span style={{ color: COLORS.accent }}>POS</span>
        </div>
        <div
          style={{
            fontSize: 40,
            fontWeight: 600,
            color: "rgba(255,255,255,0.8)",
            marginTop: 10,
            opacity: interpolate(brand, [0, 1], [0, 1]),
          }}
        >
          Kasir Macca, Usaha Maju
        </div>

        <div
          style={{
            marginTop: 80,
            fontSize: 58,
            fontWeight: 800,
            color: COLORS.white,
            lineHeight: 1.25,
            opacity: interpolate(cta, [0, 1], [0, 1]),
            transform: `translateY(${interpolate(cta, [0, 1], [40, 0])}px)`,
          }}
        >
          Coba gratis hari ini
        </div>

        <div
          style={{
            marginTop: 46,
            padding: "26px 60px",
            borderRadius: 999,
            background: COLORS.accent,
            color: COLORS.accentInk,
            fontSize: 46,
            fontWeight: 800,
            opacity: interpolate(url, [0, 1], [0, 1]),
            transform: `scale(${interpolate(url, [0, 1], [0.85, 1])})`,
          }}
        >
          kasirumkm.web.id
        </div>
      </AbsoluteFill>
    </Backdrop>
  );
};
