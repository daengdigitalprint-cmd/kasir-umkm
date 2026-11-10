import { Composition } from "remotion";
import { PromoKasir } from "./PromoKasir";
import { FPS, TOTAL_FRAMES } from "./PromoKasir/theme";

export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition
        // Render dengan: npx remotion render PromoKasir
        id="PromoKasir"
        component={PromoKasir}
        durationInFrames={TOTAL_FRAMES}
        fps={FPS}
        width={1080}
        height={1920}
        defaultProps={{ voiceover: true }}
      />
    </>
  );
};
