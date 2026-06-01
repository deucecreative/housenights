import classes from "./StatBoxes.module.scss";
import {
    IconCash,
    IconCoin,
    IconCreditCardRefund,
    IconEye,
    IconPercentage,
    IconReceipt,
    IconReceiptTax,
    IconShoppingCart,
    IconUsers
} from "@tabler/icons-react";
import {Card} from "../Card";
import {useGetEventStats} from "../../../queries/useGetEventStats.ts";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {formatNumber} from "../../../utilites/helpers.ts";
import {ReactNode} from "react";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useIsCurrentUserAdmin} from "../../../hooks/useIsCurrentUserAdmin.ts";

interface StatBoxProps {
    number: string | number;
    description: string;
    icon: ReactNode;
    backgroundColor: string;
}

export const StatBox = ({number, description, icon, backgroundColor}: StatBoxProps) => {
    return (
        <Card className={classes.statistic}>
            <div className={classes.leftPanel}>
                <div className={classes.number}>{number}</div>
                <div className={classes.description}>{description}</div>
            </div>
            <div className={classes.rightPanel}>
                <div className={classes.icon} style={{backgroundColor}}>
                    {icon}
                </div>
            </div>
        </Card>
    );
};

export const StatBoxes = () => {
    const {eventId} = useParams();
    const eventStatsQuery = useGetEventStats(eventId);
    const eventQuery = useGetEvent(eventId);
    const event = eventQuery?.data;
    const {data: eventStats} = eventStatsQuery;
    const {data: me} = useGetMe();
    const isOrganizerRole = me?.role === 'ORGANIZER';
    const isAdmin = useIsCurrentUserAdmin();

    const grossSales = eventStats?.total_gross_sales || 0;
    const totalFees = eventStats?.total_fees || 0;
    const totalTax = eventStats?.total_tax || 0;
    const salesValue = isOrganizerRole ? grossSales - totalFees : grossSales;
    const netSales = grossSales - totalTax - totalFees;

    const adminRevenueBoxes = isAdmin ? [
        {
            number: formatCurrency(totalTax, event?.currency),
            description: t`Taxes`,
            icon: <IconReceiptTax size={18}/>,
            backgroundColor: '#B7794B'
        },
        {
            number: formatCurrency(totalFees, event?.currency),
            description: t`Fees`,
            icon: <IconPercentage size={18}/>,
            backgroundColor: '#A14BB7'
        },
        {
            number: formatCurrency(netSales, event?.currency),
            description: t`Net (ex tax & fees)`,
            icon: <IconCoin size={18}/>,
            backgroundColor: '#4BB77C'
        }
    ] : [];

    const data = [
        {
            number: formatNumber(eventStats?.total_attendees_registered as number),
            description: t`Attendees`,
            icon: <IconUsers size={18}/>,
            backgroundColor: '#E6677E'
        },
        {
            number: formatNumber(eventStats?.total_products_sold as number),
            description: t`Products sold`,
            icon: <IconShoppingCart size={18}/>,
            backgroundColor: '#4B7BE5'
        },
        {
            number: formatCurrency(eventStats?.total_refunded as number || 0, event?.currency),
            description: t`Refunded`,
            icon: <IconCreditCardRefund size={18}/>,
            backgroundColor: '#49A6B7'
        },
        {
            number: formatCurrency(salesValue, event?.currency),
            description: isOrganizerRole ? t`Net sales` : t`Gross sales`,
            icon: <IconCash size={18}/>,
            backgroundColor: '#7C63E6'
        },
        {
            number: formatNumber(eventStats?.total_views as number),
            description: t`Page views`,
            icon: <IconEye size={18}/>,
            backgroundColor: '#63B3A1'
        },
        {
            number: formatNumber(eventStats?.total_orders as number),
            description: t`Completed orders`,
            icon: <IconReceipt size={18}/>,
            backgroundColor: '#E67D49'
        },
        ...adminRevenueBoxes
    ];

    return (
        <div className={classes.statistics}>
            {data.map((stat) => (
                <StatBox
                    key={stat.description}
                    number={stat.number}
                    description={stat.description}
                    icon={stat.icon}
                    backgroundColor={stat.backgroundColor}
                />
            ))}
        </div>
    );
};
