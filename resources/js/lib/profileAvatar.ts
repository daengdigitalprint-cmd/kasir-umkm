/** Avatar default: letakkan berkas di `public/profile.webp` (URL `/profile.webp`). */
export const DEFAULT_PROFILE_IMAGE = '/profile.webp';

export function resolveProfileImageSrc(avatar: string | null | undefined): string {
    if (avatar != null && String(avatar).trim() !== '') {
        return String(avatar).trim();
    }
    return DEFAULT_PROFILE_IMAGE;
}
