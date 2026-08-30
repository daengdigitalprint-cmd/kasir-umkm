/** Augmentasi Window untuk PWA — di-import dari app.ts & PwaInstallBanner agar TS/ESLint mengenali properti. */
export {};

declare global {
    interface Window {
        deferredPwaPrompt?:
            | (Event & {
                  prompt: () => Promise<void>;
                  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
              })
            | null;
    }
}
