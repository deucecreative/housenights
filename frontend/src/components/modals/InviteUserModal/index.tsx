import { useForm } from "@mantine/form";
import { GenericModalProps, InviteUserRequest, } from "../../../types.ts";
import { Modal } from "../../common/Modal";
import { Button, MultiSelect, SimpleGrid, TextInput } from "@mantine/core";
import { useFormErrorResponseHandler } from "../../../hooks/useFormErrorResponseHandler.tsx";
import { t, Trans } from "@lingui/macro";
import { useInviteUser } from "../../../mutations/useInviteUser.ts";
import { CustomSelect, ItemProps } from "../../common/CustomSelect";
import { IconUser, IconUserShield } from "@tabler/icons-react";
import { showSuccess } from "../../../utilites/notifications.tsx";
import { useGetOrganizers } from "../../../queries/useGetOrganizers.ts";

export const InviteUserModal = ({ onClose }: GenericModalProps) => {
    const createMutation = useInviteUser();
    const formErrorHandler = useFormErrorResponseHandler();
    const { data: organizersResponse, isLoading: isLoadingOrganizers } = useGetOrganizers();

    const form = useForm<InviteUserRequest>({
        initialValues: {
            email: '',
            first_name: '',
            last_name: '',
            role: 'ADMIN',
            organizer_ids: [],
        },
    });

    const handleCreate = (values: InviteUserRequest) => {
        const submitValues = { ...values };
        if (values.role !== 'ORGANIZER') {
            delete submitValues.organizer_ids;
        }

        createMutation.mutate({
            inviteUserData: submitValues,
        }, {
            onSuccess: () => {
                form.reset();
                onClose();
                showSuccess(<Trans>Success! {values.first_name} will receive an email shortly.</Trans>);
            },
            onError: (error: any) => formErrorHandler(form, error)
        });
    };

    const calcTypeOptions: ItemProps[] = [
        {
            icon: <IconUserShield />,
            label: t`Admin`,
            value: 'ADMIN',
            description: t`Admin users have full access to events and account settings.`,
        },
        {
            icon: <IconUser />,
            label: t`Organizer`,
            value: 'ORGANIZER',
            description: t`Organizers can only manage events and products for their assigned organizers.`,
        },
    ];

    const organizerOptions = (organizersResponse?.data || []).map((org) => ({
        value: String(org.id),
        label: org.name,
    }));

    return (
        <Modal heading={t`Invite a team member`} onClose={onClose} opened modalHeader={'branded'}>
            <form onSubmit={form.onSubmit(values => handleCreate(values))}>
                <SimpleGrid cols={2}>
                    <TextInput required {...form.getInputProps('first_name')} label={t`First Name`} />
                    <TextInput  {...form.getInputProps('last_name')} label={t`Last Name`} />
                </SimpleGrid>

                <TextInput required type={'email'}  {...form.getInputProps('email')} label={t`Email`} />

                <CustomSelect
                    label={t`Role`}
                    optionList={calcTypeOptions}
                    form={form}
                    name={'role'}
                />

                {form.values.role === 'ORGANIZER' && (
                    <MultiSelect
                        label={t`Assigned Organizers`}
                        description={t`Select which organizers this user can manage. Leave empty for access to all.`}
                        placeholder={t`Select organizers...`}
                        data={organizerOptions}
                        disabled={isLoadingOrganizers}
                        searchable
                        value={(form.values.organizer_ids || []).map(String)}
                        onChange={(values) => form.setFieldValue('organizer_ids', values.map(Number))}
                        mt="sm"
                    />
                )}

                <Button
                    fullWidth
                    loading={createMutation.isPending}
                    type={'submit'}>
                    {t`Invite Team Member`}
                </Button>
            </form>
        </Modal>
    )
}

