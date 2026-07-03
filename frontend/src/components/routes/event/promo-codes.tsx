import {useState} from "react";
import {useParams} from "react-router";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {PageTitle} from "../../common/PageTitle";
import {PageBody} from "../../common/PageBody";
import {PromoCodeTable} from "../../common/PromoCodeTable";
import {SearchBarWrapper} from "../../common/SearchBar";
import {useDisclosure} from "@mantine/hooks";
import {Pagination} from "../../common/Pagination";
import {Button} from "@mantine/core";
import {IconDownload, IconPlus} from "@tabler/icons-react";
import {ToolBar} from "../../common/ToolBar";
import {useGetEventPromoCodes} from "../../../queries/useGetEventPromoCodes.ts";
import {CreatePromoCodeModal} from "../../modals/CreatePromoCodeModal";
import {useFilterQueryParamSync} from "../../../hooks/useFilterQueryParamSync.ts";
import {IdParam, QueryFilters} from "../../../types.ts";
import {TableSkeleton} from "../../common/TableSkeleton";
import {t} from "@lingui/macro";
import {promoCodeClient} from "../../../api/promo-code.client.ts";
import {downloadBinary} from "../../../utilites/download.ts";
import {withLoadingNotification} from "../../../utilites/withLoadingNotification.tsx";

export const PromoCodes = () => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const promoCodesQuery = useGetEventPromoCodes(eventId, searchParams as QueryFilters);
    const promoCodes = promoCodesQuery?.data?.data;
    const pagination = promoCodesQuery?.data?.meta;
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);
    const [downloadPending, setDownloadPending] = useState(false);

    const handleExport = async (eventId: IdParam) => {
        await withLoadingNotification(async () => {
                setDownloadPending(true);
                const blob = await promoCodeClient.exportPromoCodes(eventId);
                downloadBinary(blob, 'promo-codes.xlsx');
            },
            {
                loading: {
                    title: t`Exporting Promo Codes`,
                    message: t`Please wait while we prepare your promo codes for export...`
                },
                success: {
                    title: t`Promo Codes Exported`,
                    message: t`Your promo codes have been exported successfully.`,
                    onRun: () => setDownloadPending(false)
                },
                error: {
                    title: t`Failed to export promo codes`,
                    message: t`Please try again.`,
                    onRun: () => setDownloadPending(false)
                }
            });
    };

    return (
        <>
            <PageBody>
                <PageTitle
                    subheading={t`Create discounts, access codes for hidden tickets, and special offers.`}
                >{t`Promo Codes`}</PageTitle>
                <ToolBar searchComponent={() => (
                    <SearchBarWrapper
                        placeholder={t`Search by name...`}
                        setSearchParams={setSearchParams}
                        searchParams={searchParams}
                        pagination={pagination}
                    />
                )}>
                    <Button
                        onClick={() => handleExport(eventId)}
                        rightSection={<IconDownload size={14}/>}
                        color={'green'}
                        variant={'light'}
                        loading={downloadPending}
                        size={'sm'}
                    >
                        {t`Export`}
                    </Button>
                    <Button color={'green'} size={'sm'} onClick={openCreateModal} rightSection={<IconPlus/>}>
                        {t`Create`}
                    </Button>

                </ToolBar>

                <TableSkeleton isVisible={!promoCodes || !event}/>

                {(promoCodes && event) &&
                    <PromoCodeTable
                        openCreateModal={openCreateModal}
                        event={event}
                        promoCodes={promoCodes}
                    />}

                {!!promoCodes?.length && (
                    <Pagination
                        value={searchParams.pageNumber}
                        onChange={(value) => setSearchParams({pageNumber: value})}
                        total={Number(pagination?.last_page)}
                    />
                )}

            </PageBody>
            {createModalOpen && <CreatePromoCodeModal onClose={closeCreateModal} isOpen/>}
        </>
    );
};

export default PromoCodes;
