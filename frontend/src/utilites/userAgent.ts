/**
 * Returns true when the current user agent looks like iOS (iPhone/iPad/iPod),
 * which is where Apple Wallet `.pkpass` downloads are natively supported in
 * mobile Safari. Excludes desktop macOS since the UX of an "Add to Apple
 * Wallet" button on macOS Safari is ambiguous (it does download a .pkpass
 * but most users don't expect that flow).
 *
 * SSR-safe: returns false when `navigator` is unavailable.
 */
export const isIOS = (): boolean => {
    if (typeof navigator === "undefined") {
        return false;
    }

    const ua = navigator.userAgent || "";

    // iPad on iOS 13+ identifies as Mac with touch support — handle that.
    const iPadOS13Up =
        ua.includes("Macintosh") &&
        typeof (navigator as Navigator & { maxTouchPoints?: number }).maxTouchPoints === "number" &&
        ((navigator as Navigator & { maxTouchPoints?: number }).maxTouchPoints ?? 0) > 1;

    return /iPhone|iPad|iPod/i.test(ua) || iPadOS13Up;
};
