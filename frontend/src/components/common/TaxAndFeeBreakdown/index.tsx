import {useParams} from "react-router";
import {Table} from "@mantine/core";
import {t} from "@lingui/macro";
import {useGetEventStats} from "../../../queries/useGetEventStats.ts";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {useIsCurrentUserAdmin} from "../../../hooks/useIsCurrentUserAdmin.ts";
import {Card} from "../Card";
import {TaxFeeBreakdownItem} from "../../../types.ts";
import classes from "./TaxAndFeeBreakdown.module.scss";

/**
 * Admin-only card showing the per-name breakdown of each distinct tax and fee
 * collected for an event. Renders nothing for non-admins or when there is no
 * breakdown data (the API only returns it to admins).
 */
export const TaxAndFeeBreakdown = () => {
    const {eventId} = useParams();
    const isAdmin = useIsCurrentUserAdmin();
    const {data: eventStats} = useGetEventStats(eventId);
    const {data: event} = useGetEvent(eventId);

    const breakdown = eventStats?.taxes_and_fees_breakdown;

    if (!isAdmin || !breakdown || breakdown.length === 0) {
        return null;
    }

    const taxes = breakdown.filter((item) => item.kind === 'TAX');
    const fees = breakdown.filter((item) => item.kind === 'FEE');

    const renderRows = (items: TaxFeeBreakdownItem[]) => items.map((item) => (
        <Table.Tr key={`${item.kind}-${item.name}-${item.rate}`}>
            <Table.Td>{item.name || t`Unnamed`}</Table.Td>
            <Table.Td ta="right">{formatCurrency(item.total_collected, event?.currency)}</Table.Td>
        </Table.Tr>
    ));

    const renderSection = (label: string, items: TaxFeeBreakdownItem[]) => {
        if (items.length === 0) {
            return null;
        }

        const total = items.reduce((sum, item) => sum + Number(item.total_collected || 0), 0);

        return (
            <Table withRowBorders={false} className={classes.table}>
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>{label}</Table.Th>
                        <Table.Th ta="right">{t`Collected`}</Table.Th>
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    {renderRows(items)}
                    <Table.Tr className={classes.totalRow}>
                        <Table.Td>{t`Total`}</Table.Td>
                        <Table.Td ta="right">{formatCurrency(total, event?.currency)}</Table.Td>
                    </Table.Tr>
                </Table.Tbody>
            </Table>
        );
    };

    return (
        <Card className={classes.breakdownCard}>
            <div className={classes.cardTitle}>
                <h2>{t`Taxes & Fees Breakdown`}</h2>
            </div>
            <div className={classes.sections}>
                {renderSection(t`Taxes`, taxes)}
                {renderSection(t`Fees`, fees)}
            </div>
        </Card>
    );
};

export default TaxAndFeeBreakdown;
