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

/**
 * Returns true when the current user agent looks like Android, which is
 * where the "Save to Google Wallet" flow is most useful (saves directly
 * into the Wallet app). Google Wallet links also work on desktop
 * browsers — callers decide whether to surface the button there too.
 *
 * SSR-safe: returns false when `navigator` is unavailable.
 */
export const isAndroid = (): boolean => {
    if (typeof navigator === "undefined") {
        return false;
    }

    const ua = navigator.userAgent || "";
    return /Android/i.test(ua);
};
