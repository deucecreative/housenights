import {t} from "@lingui/macro";

import {Attendee, Event} from "../../../types.ts";
import {getConfig} from "../../../utilites/config.ts";
import {isIOS} from "../../../utilites/userAgent.ts";

interface TicketWalletButtonsProps {
    event: Event;
    attendee: Attendee;
    /** Visual height of the badge in pixels. Defaults to 44 (Apple's recommended size). */
    height?: number;
}

/**
 * Renders the platform-appropriate "Add to Wallet" badge for a single attendee.
 *
 * Returns null when:
 * - the attendee is cancelled or awaiting payment
 * - the event hasn't enabled wallet passes (`event.settings.wallet_passes_enabled`)
 *
 * iOS visitors see the Apple Wallet badge; everyone else gets the Google Wallet badge.
 * The wallet endpoints are public (the attendee short_id is the unguessable token),
 * so no auth is needed on the consumer page.
 */
export const TicketWalletButtons = ({event, attendee, height = 44}: TicketWalletButtonsProps) => {
    if (attendee.status === 'CANCELLED' || attendee.status === 'AWAITING_PAYMENT') {
        return null;
    }
    if (!event?.settings?.wallet_passes_enabled) {
        return null;
    }

    const apiBase = getConfig('VITE_API_URL_CLIENT') ?? '';
    const badgeStyle = {height: `${height}px`, width: 'auto', display: 'block'} as const;

    if (isIOS()) {
        return (
            <a
                href={`${apiBase}/public/attendee/${event.id}/${attendee.short_id}/apple-pass.pkpass`}
                style={{display: 'inline-block', textDecoration: 'none', lineHeight: 0}}
                aria-label={t`Add to Apple Wallet`}
            >
                <img
                    src="https://developer.apple.com/wallet/add-to-apple-wallet-guidelines/images/add-to-apple-wallet/Add_to_Apple_Wallet_rgb_US-UK.png"
                    alt={t`Add to Apple Wallet`}
                    style={badgeStyle}
                />
            </a>
        );
    }

    return (
        <button
            type="button"
            onClick={async () => {
                try {
                    const res = await fetch(
                        `${apiBase}/public/attendee/${event.id}/${attendee.short_id}/google-pass-link`,
                    );
                    if (!res.ok) {
                        return;
                    }
                    const data = await res.json() as { url?: string };
                    if (data?.url) {
                        window.location.href = data.url;
                    }
                } catch {
                    // Silently fail — button just doesn't navigate.
                }
            }}
            style={{
                display: 'inline-block',
                padding: 0,
                border: 0,
                background: 'transparent',
                cursor: 'pointer',
                lineHeight: 0,
            }}
            aria-label={t`Add to Google Wallet`}
        >
            <img
                src="https://developers.google.com/wallet/static/images/branding/Add-to-Google-Wallet-button.png"
                alt={t`Add to Google Wallet`}
                style={badgeStyle}
            />
        </button>
    );
};
