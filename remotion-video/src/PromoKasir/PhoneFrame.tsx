import React from "react";
import { AbsoluteFill, Img, interpolate, spring, useCurrentFrame, useVideoConfig } from "remotion";
import { COLORS } from "./theme";

/**
 * Bingkai ponsel berisi screenshot asli aplikasi.
 * Gambar diperbesar perlahan (Ken Burns) supaya adegan statis tetap hidup.
 */
export const PhoneFrame: React.FC<{
  src: string;
  /** Frame saat bingkai mulai masuk, relatif terhadap adegan. */
  delay?: number;
  width?: number;
}> = ({ src, delay = 0, width = 620 }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const enter = spring({
    frame: frame - delay,
    fps,
    config: { damping: 200, mass: 0.8 },
  });

  const lift = interpolate(enter, [0, 1], [90, 0]);
  const fade = interpolate(enter, [0, 1], [0, 1]);
  const zoom = interpolate(frame, [0, 330], [1, 1.07], {
    extrapolateRight: "clamp",
  });

  const height = (width / 430) * 932;

  return (
    <AbsoluteFill style={{ alignItems: "center", justifyContent: "center" }}>
      <div
        style={{
          width,
          height,
          transform: `translateY(${lift}px)`,
          opacity: fade,
          borderRadius: 54,
          padding: 12,
          background: "linear-gradient(160deg, #2a2a35 0%, #0c0c12 100%)",
          boxShadow: "0 60px 120px rgba(15,23,42,0.45), 0 0 0 2px rgba(255,255,255,0.06) inset",
        }}
      >
        <div
          style={{
            width: "100%",
            height: "100%",
            borderRadius: 43,
            overflow: "hidden",
            background: COLORS.white,
            position: "relative",
          }}
        >
          <Img
            src={src}
            style={{
              width: "100%",
              height: "100%",
              objectFit: "cover",
              objectPosition: "top center",
              transform: `scale(${zoom})`,
              transformOrigin: "top center",
            }}
          />
        </div>
      </div>
    </AbsoluteFill>
  );
};

/**
 * Bingkai jendela browser untuk screenshot desktop (laporan, dashboard lebar).
 * Digeser pelan ke samping agar bagian kanan tabel sempat terlihat.
 */
export const BrowserFrame: React.FC<{
  src: string;
  delay?: number;
  width?: number;
}> = ({ src, delay = 0, width = 980 }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const enter = spring({
    frame: frame - delay,
    fps,
    config: { damping: 200, mass: 0.9 },
  });

  const lift = interpolate(enter, [0, 1], [70, 0]);
  const fade = interpolate(enter, [0, 1], [0, 1]);
  const drift = interpolate(frame, [0, 330], [0, -70], {
    extrapolateRight: "clamp",
  });

  const height = (width / 1440) * 900;

  return (
    <AbsoluteFill style={{ alignItems: "center", justifyContent: "center" }}>
      <div
        style={{
          width,
          transform: `translateY(${lift}px)`,
          opacity: fade,
          borderRadius: 28,
          overflow: "hidden",
          background: COLORS.white,
          boxShadow: "0 50px 100px rgba(15,23,42,0.35)",
        }}
      >
        <div
          style={{
            height: 46,
            background: "#e9edf5",
            display: "flex",
            alignItems: "center",
            gap: 10,
            paddingLeft: 22,
          }}
        >
          {["#ff5f57", "#febc2e", "#28c840"].map((c) => (
            <div key={c} style={{ width: 14, height: 14, borderRadius: 999, background: c }} />
          ))}
        </div>
        <div style={{ height: height, overflow: "hidden" }}>
          <Img
            src={src}
            style={{
              width: "100%",
              display: "block",
              transform: `translateY(${drift}px)`,
            }}
          />
        </div>
      </div>
    </AbsoluteFill>
  );
};
