import React from "react";
import { interpolate, spring, useCurrentFrame, useVideoConfig } from "remotion";
import { COLORS, FONT } from "./theme";

/** Judul besar di atas layar, muncul kata demi kata. */
export const SceneTitle: React.FC<{
  kicker: string;
  title: string;
  delay?: number;
}> = ({ kicker, title, delay = 0 }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const enter = spring({
    frame: frame - delay,
    fps,
    config: { damping: 200 },
  });

  return (
    <div
      style={{
        position: "absolute",
        top: 110,
        left: 0,
        right: 0,
        textAlign: "center",
        fontFamily: FONT,
        opacity: interpolate(enter, [0, 1], [0, 1]),
        transform: `translateY(${interpolate(enter, [0, 1], [-30, 0])}px)`,
      }}
    >
      <div
        style={{
          display: "inline-block",
          padding: "10px 26px",
          borderRadius: 999,
          background: COLORS.accent,
          color: COLORS.accentInk,
          fontSize: 30,
          fontWeight: 800,
          letterSpacing: 2,
          textTransform: "uppercase",
          marginBottom: 24,
        }}
      >
        {kicker}
      </div>
      <div
        style={{
          fontSize: 62,
          lineHeight: 1.14,
          fontWeight: 800,
          color: COLORS.white,
          padding: "0 60px",
          textShadow: "0 6px 30px rgba(0,0,0,0.25)",
        }}
      >
        {title}
      </div>
    </div>
  );
};

/** Deretan poin yang muncul berurutan di bagian bawah layar. */
export const Bullets: React.FC<{
  items: string[];
  delay?: number;
}> = ({ items, delay = 0 }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  return (
    <div
      style={{
        position: "absolute",
        bottom: 110,
        left: 0,
        right: 0,
        display: "flex",
        flexDirection: "column",
        alignItems: "center",
        gap: 18,
        fontFamily: FONT,
      }}
    >
      {items.map((item, i) => {
        const enter = spring({
          frame: frame - delay - i * 16,
          fps,
          config: { damping: 200 },
        });

        return (
          <div
            key={item}
            style={{
              display: "flex",
              alignItems: "center",
              gap: 16,
              padding: "16px 32px",
              borderRadius: 999,
              background: "rgba(255,255,255,0.95)",
              boxShadow: "0 18px 40px rgba(15,23,42,0.18)",
              opacity: interpolate(enter, [0, 1], [0, 1]),
              transform: `translateX(${interpolate(enter, [0, 1], [-60, 0])}px)`,
            }}
          >
            <div
              style={{
                width: 38,
                height: 38,
                borderRadius: 999,
                background: COLORS.primary,
                color: COLORS.white,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                fontSize: 24,
                fontWeight: 800,
              }}
            >
              ✓
            </div>
            <div style={{ fontSize: 34, fontWeight: 700, color: COLORS.ink }}>
              {item}
            </div>
          </div>
        );
      })}
    </div>
  );
};
