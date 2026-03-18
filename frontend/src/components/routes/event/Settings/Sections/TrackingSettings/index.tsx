import {Button, Stack, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {useEffect} from "react";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {showSuccess} from "../../../../../../utilites/notifications";
import {useParams} from "react-router";

export const TrackingSettings = () => {
    const {eventId} = useParams();
    const {data: settings} = useGetEventSettings(eventId);
    const updateMutation = useUpdateEventSettings();

    const form = useForm({
        initialValues: {
            meta_pixel_id: settings?.meta_pixel_id || "",
            meta_conversions_api_access_token: settings?.meta_conversions_api_access_token || "",
        },
    });

    useEffect(() => {
        if (settings) {
            form.setValues({
                meta_pixel_id: settings.meta_pixel_id || "",
                meta_conversions_api_access_token: settings.meta_conversions_api_access_token || "",
            });
            form.resetDirty();
        }
    }, [settings]);

    const submit = (values: typeof form.values) => {
        updateMutation.mutate({
            eventSettings: values,
            eventId: eventId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully updated Tracking Settings`);
                form.resetDirty();
            },
        });
    };

    return (
        <form onSubmit={form.onSubmit(submit)}>
            <Stack gap="lg">
                <Card>
                    <HeadingWithDescription
                        heading={t`Meta (Facebook) Tracking`}
                        description={t`Track conversions and page views. For best results, use both the Pixel ID and the Conversions API Access Token. We automatically hash customer data to improve event match quality.`}
                    />
                    <Stack gap="md" mt="md">
                        <TextInput
                            label={t`Meta Pixel ID`}
                            description={t`e.g., 123456789012345`}
                            placeholder={t`Enter your Meta Pixel ID`}
                            {...form.getInputProps("meta_pixel_id")}
                        />
                        <TextInput
                            label={t`Conversions API Access Token`}
                            description={t`e.g., EAXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX`}
                            placeholder={t`Enter your Meta Conversions API Access Token`}
                            {...form.getInputProps("meta_conversions_api_access_token")}
                        />
                    </Stack>
                </Card>
            </Stack>
            <div className="sticky-save-button">
                <Button type="submit" disabled={!form.isDirty()} loading={updateMutation.isPending}>{t`Save`}</Button>
            </div>
        </form>
    );
};
