import {useParams} from "react-router";
import {useGetEvent} from "../../../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {useIsCurrentUserAdmin} from "../../../../../hooks/useIsCurrentUserAdmin.ts";
import ReportTable from "../../../../common/ReportTable";

interface ProductSalesRow {
    product_id: number;
    product_title: string;
    product_type: string;
    price_tier_id: number | null;
    price_tier_label: string | null;
    total_tax: number;
    total_gross: number;
    total_service_fees: number;
    number_sold: number;
    // Admin-only: per-name fee amounts (e.g. {"Booking Fee": 12.5}). Absent for organisers.
    fees_breakdown?: Record<string, number>;

    [key: string]: any;
}

const ProductSalesReport = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const event = eventQuery.data;
    const isAdmin = useIsCurrentUserAdmin();

    if (!event) {
        return null;
    }

    const columns = [
        {
            key: 'product_title' as const,
            label: 'Product Title',
            sortable: true
        },
        {
            key: 'number_sold' as const,
            label: 'Units Sold',
            sortable: true
        },
        {
            key: 'total_gross' as const,
            label: 'Gross Sales',
            sortable: true,
            render: (value: number) => formatCurrency(value, event?.currency)
        },
        {
            key: 'total_tax' as const,
            label: 'Tax',
            sortable: true,
            render: (value: number) => formatCurrency(value, event?.currency)
        },
        {
            key: 'total_service_fees' as const,
            label: 'Service Fees',
            sortable: true,
            render: (value: number) => formatCurrency(value, event?.currency)
        }
    ];

    // Admins additionally see a column per distinct fee, derived from the data.
    const getDynamicColumns = isAdmin
        ? (rows: ProductSalesRow[]) => {
            const names = new Set<string>();
            rows.forEach((row) => {
                if (row.fees_breakdown) {
                    Object.keys(row.fees_breakdown).forEach((name) => names.add(name));
                }
            });

            return Array.from(names).sort().map((name) => ({
                key: (`fee::${name}`) as keyof ProductSalesRow,
                label: name || 'Fee',
                sortable: false,
                accessor: (row: ProductSalesRow) => row.fees_breakdown?.[name] ?? 0,
                render: (_value: unknown, row: ProductSalesRow) =>
                    formatCurrency(row.fees_breakdown?.[name] ?? 0, event?.currency),
            }));
        }
        : undefined;

    return (
        <ReportTable<ProductSalesRow>
            title="Product Sales Report"
            columns={columns}
            getDynamicColumns={getDynamicColumns}
            groupByKey="product_id"
            groupLabelKey="product_title"
            subRowLabelKey="price_tier_label"
            aggregatableKeys={['number_sold', 'total_gross', 'total_tax', 'total_service_fees']}
            mergeObjectKeys={['fees_breakdown']}
            isLoading={eventQuery.isLoading}
            downloadFileName="product_sales_report.csv"
            event={event}
        />
    );
};

export default ProductSalesReport;
