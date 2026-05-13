import {getAttendeeProductPrice, getAttendeeProductTitle} from "../../../utilites/products.ts";
import {Button, CopyButton} from "@mantine/core";
import {formatCurrency} from "../../../utilites/currency.ts";
import {t} from "@lingui/macro";
import {prettyDate} from "../../../utilites/dates.ts";
import QRCode from "react-qr-code";
import {IconCopy, IconPrinter, IconLock, IconX} from "@tabler/icons-react";
import {Address, Attendee, Event, Product} from "../../../types.ts";
import classes from './AttendeeTicket.module.scss';
import {imageUrl} from "../../../utilites/urlHelper.ts";
import {formatAddress} from "../../../utilites/addressUtilities.ts";
import {isAndroid, isIOS} from "../../../utilites/userAgent.ts";
import {getConfig} from "../../../utilites/config.ts";
import {PoweredByFooter} from "../PoweredByFooter";

interface AttendeeTicketProps {
    event: Event;
    attendee: Attendee;
    product: Product;
    hideButtons?: boolean;
    showPoweredBy?: boolean;
}

export const AttendeeTicket = ({
                                   attendee,
                                   product,
                                   event,
                                   hideButtons = false,
                                   showPoweredBy = false,
                               }: AttendeeTicketProps) => {
    const productPrice = getAttendeeProductPrice(attendee, product);
    const hasVenue = event?.settings?.location_details?.venue_name || event?.settings?.location_details?.address_line_1;

    const ticketDesignSettings = event?.settings?.ticket_design_settings;
    const accentColor = ticketDesignSettings?.accent_color || '#6B46C1';
    const footerText = ticketDesignSettings?.footer_text;
    const logoUrl = imageUrl('TICKET_LOGO', event?.images);

    const ticketStyle = {
        '--accent': accentColor,
    } as React.CSSProperties;

    const isCancelled = attendee.status === 'CANCELLED';
    const isAwaitingPayment = attendee.status === 'AWAITING_PAYMENT';

    // Generate a deterministic pattern based on attendee ID for consistency
    const generateQrPattern = () => {
        const seed = attendee.public_id || 'default';
        const pattern = [];
        for (let i = 0; i < 64; i++) {
            const charCode = seed.charCodeAt(i % seed.length);
            pattern.push((charCode + i) % 2 === 0);
        }
        return pattern;
    };

    const qrPattern = generateQrPattern();

    return (
        <div className={classes.ticket} style={ticketStyle}>
            {/* Header */}
            <div className={classes.header}>
                <div className={classes.headerContent}>
                    <h1 className={classes.eventTitle}>{event?.title}</h1>
                    <div className={classes.priceDisplay}>
                        {productPrice > 0 ? formatCurrency(productPrice, event?.currency) : t`Free`}
                    </div>
                </div>
            </div>

            {/* Main Content */}
            <div className={classes.content}>
                <div className={classes.contentLeft}>
                    {/* Event Details */}
                    <div className={classes.eventDetails}>
                        <div className={classes.detailRow}>
                            <div className={classes.detailLabel}>{t`Date & Time`}</div>
                            <div className={classes.detailValue}>
                                {prettyDate(event.start_date, event.timezone, true)}
                            </div>
                        </div>
                        {event?.organizer?.name && (
                            <div className={classes.detailRow}>
                                <div className={classes.detailLabel}>{t`Organizer`}</div>
                                <div className={classes.detailValue}>
                                    {event?.organizer?.name}
                                </div>
                            </div>
                        )}

                        {hasVenue && (
                            <div className={classes.detailRow}>
                                <div className={classes.detailLabel}>{t`Location`}</div>
                                <div className={classes.detailValue}>
                                    {formatAddress(event?.settings?.location_details as Address)}
                                </div>
                            </div>
                        )}

                        <div className={classes.detailRow}>
                            <div className={classes.detailLabel}>{t`Ticket Type`}</div>
                            <div className={classes.detailValue}>
                                {getAttendeeProductTitle(attendee, product)}
                            </div>
                        </div>
                    </div>

                    {/* Attendee Information */}
                    <div className={classes.attendeeSection}>
                        <div className={classes.detailLabel}>{t`Attendee`}</div>
                        <div className={classes.attendeeName}>
                            {attendee.first_name} {attendee.last_name}
                        </div>
                        <div className={classes.attendeeEmail}>{attendee.email}</div>
                    </div>

                </div>

                {/* Right Section - Logo & QR Code */}
                <div className={classes.contentRight}>
                    <div className={classes.qrSection}>
                        {logoUrl && (
                            <div className={classes.logoContainer}>
                                <img src={logoUrl} alt="Event Logo" className={classes.logo}/>
                            </div>
                        )}

                        {/* QR Code or Status Placeholder */}
                        {(isCancelled || isAwaitingPayment) ? (
                            <div className={`${classes.qrPlaceholder} ${isCancelled ? classes.qrPlaceholderCancelled : classes.qrPlaceholderPending}`}>
                                {/* Faded QR Pattern Background */}
                                <div className={classes.qrPatternBackground}>
                                    {qrPattern.map((filled, i) => (
                                        <div
                                            key={i}
                                            className={`${classes.qrPatternCell} ${filled ? classes.qrPatternCellFilled : ''}`}
                                        />
                                    ))}
                                </div>

                                {/* Status Content Overlay */}
                                <div className={classes.qrPlaceholderContent}>
                                    <div className={`${classes.statusIconCircle} ${isCancelled ? classes.statusIconCancelled : classes.statusIconPending}`}>
                                        {isCancelled ? (
                                            <IconX size={20} stroke={2} color="white" />
                                        ) : (
                                            <IconLock size={20} stroke={2} color="white" />
                                        )}
                                    </div>
                                    <span className={`${classes.statusText} ${isCancelled ? classes.statusTextCancelled : classes.statusTextPending}`}>
                                        {isCancelled ? t`Cancelled` : t`Pay to unlock`}
                                    </span>
                                </div>
                            </div>
                        ) : (
                            <div
                                className={classes.qrContainer}
                                style={{borderColor: accentColor}}
                            >
                                <QRCode
                                    value={String(attendee.public_id)}
                                    size={180}
                                    level="M"
                                    style={{height: "auto", maxWidth: "100%", width: "100%"}}
                                />
                            </div>
                        )}

                        <div className={classes.ticketId}>
                            <div className={classes.detailLabel}>{t`Ticket ID`}</div>
                            <div
                                className={classes.ticketIdValue}
                                style={{color: accentColor}}
                            >{attendee.public_id}</div>
                        </div>

                        {!isCancelled && !isAwaitingPayment && event?.settings?.wallet_passes_enabled && isIOS() && (
                            <a
                                href={`${getConfig('VITE_API_URL_CLIENT')}/public/attendee/${event.id}/${attendee.short_id}/apple-pass`}
                                style={{
                                    display: 'inline-block',
                                    marginTop: '12px',
                                    textDecoration: 'none',
                                    lineHeight: 0,
                                }}
                                aria-label={t`Add to Apple Wallet`}
                            >
                                {/* TODO: replace with local SVG once frontend/src/assets/wallet/apple-add-to-wallet.svg is committed */}
                                <img
                                    src="https://developer.apple.com/wallet/add-to-apple-wallet-guidelines/images/add-to-apple-wallet/Add_to_Apple_Wallet_rgb_US-UK.png"
                                    alt={t`Add to Apple Wallet`}
                                    style={{height: '44px', width: 'auto', display: 'block'}}
                                />
                            </a>
                        )}

                        {!isCancelled && !isAwaitingPayment && event?.settings?.wallet_passes_enabled && !isIOS() && (isAndroid() || typeof window !== 'undefined') && (
                            <button
                                type="button"
                                onClick={async () => {
                                    try {
                                        const res = await fetch(
                                            `${getConfig('VITE_API_URL_CLIENT')}/public/attendee/${event.id}/${attendee.short_id}/google-pass-link`,
                                        );
                                        if (!res.ok) {
                                            return;
                                        }
                                        const data = await res.json() as { url?: string };
                                        if (data?.url) {
                                            window.location.href = data.url;
                                        }
                                    } catch {
                                        // Silently fail — the button just doesn't navigate.
                                    }
                                }}
                                style={{
                                    display: 'inline-block',
                                    marginTop: '12px',
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
                                    style={{height: '44px', width: 'auto', display: 'block'}}
                                />
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {/* Footer - Only show if there's footer text or buttons */}
            {(footerText || !hideButtons) && (
                <div className={classes.footer}>
                    <div className={classes.footerContent}>
                        {footerText && (
                            <div className={classes.footerText}>
                                {footerText}
                            </div>
                        )}

                        {!hideButtons && (
                            <div className={classes.actions}>
                                <Button
                                    variant="default"
                                    size="sm"
                                    onClick={() => window?.open(`/product/${event.id}/${attendee.short_id}/print`, '_blank')}
                                    leftSection={<IconPrinter size={16}/>}
                                >
                                    {t`Print to PDF`}
                                </Button>

                                <CopyButton
                                    value={`${window?.location.origin}/product/${event.id}/${attendee.short_id}`}>
                                    {({copied, copy}) => (
                                        <Button
                                            variant="default"
                                            size="sm"
                                            onClick={copy}
                                            leftSection={<IconCopy size={16}/>}
                                        >
                                            {copied ? t`Copied` : t`Copy Link`}
                                        </Button>
                                    )}
                                </CopyButton>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Powered By - Only shown in print mode */}
            {showPoweredBy && (
                <div className={classes.poweredByInTicket}>
                    <PoweredByFooter/>
                </div>
            )}
        </div>
    );
}
