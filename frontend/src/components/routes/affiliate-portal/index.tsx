import { t } from "@lingui/macro";
import { useParams } from "react-router";
import { Text } from "@mantine/core";
import {
    IconAlertCircle,
    IconUsers,
    IconChartBar,
} from "@tabler/icons-react";
import { useQuery } from "@tanstack/react-query";

import { affiliatePortalClient, AffiliatePortalData } from "../../../api/affiliate-portal.client";

import { Card } from "../../common/Card";
import { LoadingMask } from "../../common/LoadingMask";
import { PoweredByFooter } from "../../common/PoweredByFooter";
import { CheckoutContent } from "../../layouts/Checkout/CheckoutContent";

import classes from './AffiliatePortal.module.scss';

const useGetAffiliateByToken = (token: string | undefined) => {
    return useQuery<AffiliatePortalData, Error>({
        queryKey: ['affiliate-portal', token],
        queryFn: async () => {
            if (!token) {
                throw new Error('No token provided');
            }
            const response = await affiliatePortalClient.getAffiliateByToken(token);
            return response.data;
        },
        enabled: !!token,
        retry: false,
    });
};



const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

/**
 * Format affiliate term with proper pluralization and capitalization
 */
const formatAffiliateTerm = (
    term: string | null | undefined,
    options: { plural?: boolean; capitalize?: boolean } = {}
) => {
    const base = term || 'affiliate';

    // Simple pluralization rules
    let result = base;
    if (options.plural) {
        if (base.toLowerCase() === 'dj') {
            result = 'DJs';
        } else if (base.endsWith('y')) {
            result = base.slice(0, -1) + 'ies';
        } else if (base.endsWith('s') || base.endsWith('x') || base.endsWith('ch') || base.endsWith('sh')) {
            result = base + 'es';
        } else {
            result = base + 's';
        }
    }

    if (options.capitalize) {
        result = result.charAt(0).toUpperCase() + result.slice(1);
    }

    return result;
};

export const AffiliatePortal = () => {
    const { token } = useParams();
    const { data, isLoading, isError, error } = useGetAffiliateByToken(token);

    if (isLoading) {
        return <LoadingMask />;
    }

    if (isError || !data) {
        return (
            <CheckoutContent>
                <div className={classes.container}>
                    <div className={classes.header}>
                        <IconAlertCircle size={48} className={classes.headerIconError} />
                        <h1>{t`Link Expired or Invalid`}</h1>
                        <p className={classes.subtitle}>
                            {t`This link is no longer valid.`}
                        </p>
                        <a
                            href="/affiliate/login"
                            className={classes.requestLinkButton}
                        >
                            {t`Request a New Magic Link`}
                        </a>
                    </div>
                </div>
                <PoweredByFooter />
            </CheckoutContent>
        );
    }

    const { affiliate, event, orders } = data;

    return (
        <CheckoutContent>
            <div className={classes.container}>
                <div className={classes.header}>
                    <IconChartBar size={48} className={classes.headerIcon} />
                    <h1>{formatAffiliateTerm(event.affiliate_term, { capitalize: true })} Dashboard</h1>
                    <Text className={classes.eventTitle}>{event.title}</Text>
                    <p className={classes.subtitle}>
                        {t`Welcome back, ${affiliate.name}! Here's your performance overview.`}
                    </p>
                </div>

                <div className={classes.statsGrid}>
                    <Card className={classes.statCard}>
                        <IconUsers size={24} style={{ color: 'var(--mantine-color-secondary-5)', marginBottom: 8 }} />
                        <div className={classes.statValue}>{affiliate.total_sales}</div>
                        <div className={classes.statLabel}>{t`Total Sales`}</div>
                    </Card>
                </div>

                <div className={classes.ordersSection}>
                    <h2 className={classes.sectionTitle}>{t`Your Referrals`}</h2>

                    {orders.length === 0 ? (() => {
                        const affiliateUrl = `${window.location.origin}/event/${event.id}/${event.slug}?aff=${affiliate.code}`;
                        return (
                            <div className={classes.emptyOrders}>
                                <Text c="dimmed">{t`No orders yet. Share your link to start tracking sales!`}</Text>
                                <Text size="sm" mt="md" c="dimmed">{t`Your link:`}</Text>
                                <Text
                                    size="sm"
                                    mt="xs"
                                    component="a"
                                    href={affiliateUrl}
                                    target="_blank"
                                    style={{ wordBreak: 'break-all' }}
                                >
                                    {affiliateUrl}
                                </Text>
                            </div>
                        );
                    })() : (
                        <div className={classes.ordersList}>
                            {orders.map((order) => (
                                <div key={order.id} className={classes.orderRow}>
                                    <span className={classes.buyerName}>{order.buyer_name}</span>
                                    <div className={classes.orderMeta}>
                                        <span>{formatDate(order.created_at)}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
            <PoweredByFooter />
        </CheckoutContent>
    );
};

export default AffiliatePortal;
