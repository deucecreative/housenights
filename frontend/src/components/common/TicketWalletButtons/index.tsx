import {t} from "@lingui/macro";
import {ActionIcon, Tooltip} from "@mantine/core";
import {IconBrandApple, IconBrandGoogle} from "@tabler/icons-react";

import {Attendee, Event} from "../../../types.ts";
import {getConfig} from "../../../utilites/config.ts";

interface TicketWalletButtonsProps {
    event: Event;
    attendee: Attendee;
    /** Tabler icon size (defaults to 18 to match neighbouring action icons). */
    iconSize?: number;
}

/**
 * Compact Apple + Google "Add to Wallet" action icons for a single attendee.
 *
 * Returns null when:
 * - the attendee is cancelled or awaiting payment
 * - the event hasn't enabled wallet passes (`event.settings.wallet_passes_enabled`)
 *
 * Both buttons are shown regardless of device — the email behaves the same,
 * since users may save the link and open it elsewhere.
 *
 * Uses Tabler brand icons rather than the official Apple/Google badge images
 * so the buttons sit cleanly alongside the other 18px ActionIcons (View,
 * Print, Edit, Resend) on the order-summary and my-tickets pages. The big
 * official badges remain in the per-ticket view (AttendeeTicket) and in
 * confirmation emails, where they have room to breathe as standalone CTAs.
 */
export const TicketWalletButtons = ({event, attendee, iconSize = 18}: TicketWalletButtonsProps) => {
    if (attendee.status === 'CANCELLED' || attendee.status === 'AWAITING_PAYMENT') {
        return null;
    }
    if (!event?.settings?.wallet_passes_enabled) {
        return null;
    }

    const apiBase = getConfig('VITE_API_URL_CLIENT') ?? '';

    const handleGoogleClick = async () => {
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
    };

    return (
        <>
            <Tooltip label={t`Add to Apple Wallet`}>
                <ActionIcon
                    component="a"
                    href={`${apiBase}/public/attendee/${event.id}/${attendee.short_id}/apple-pass.pkpass`}
                    variant="subtle"
                    aria-label={t`Add to Apple Wallet`}
                >
                    <IconBrandApple size={iconSize}/>
                </ActionIcon>
            </Tooltip>
            <Tooltip label={t`Add to Google Wallet`}>
                <ActionIcon
                    variant="subtle"
                    onClick={handleGoogleClick}
                    aria-label={t`Add to Google Wallet`}
                >
                    <IconBrandGoogle size={iconSize}/>
                </ActionIcon>
            </Tooltip>
        </>
    );
};
