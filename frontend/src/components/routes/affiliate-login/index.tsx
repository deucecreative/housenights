import { t } from "@lingui/macro";
import { Button, Text, TextInput } from "@mantine/core";
import { useForm } from "@mantine/form";
import { useState } from "react";
import { IconMail, IconCheck } from "@tabler/icons-react";
import { useMutation } from "@tanstack/react-query";

import { affiliatePortalClient } from "../../../api/affiliate-portal.client.ts";
import { Card } from "../../common/Card";
import { PoweredByFooter } from "../../common/PoweredByFooter";
import { CheckoutContent } from "../../layouts/Checkout/CheckoutContent";

import classes from './AffiliateLogin.module.scss';

interface FormValues {
    email: string;
}

const AffiliateLogin = () => {
    const [emailSent, setEmailSent] = useState(false);

    const form = useForm<FormValues>({
        initialValues: {
            email: '',
        },
        validate: {
            email: (value) => {
                if (!value) return t`Email is required`;
                if (!/^\S+@\S+\.\S+$/.test(value)) return t`Invalid email format`;
                return null;
            }
        }
    });

    const mutation = useMutation({
        mutationFn: (email: string) => affiliatePortalClient.requestMagicLink(email),
        onSuccess: () => {
            setEmailSent(true);
        }
    });

    const handleSubmit = form.onSubmit((values) => {
        mutation.mutate(values.email);
    });

    return (
        <CheckoutContent>
            <div className={classes.container}>
                <Card className={classes.card}>
                    <div className={classes.header}>
                        <IconMail size={48} className={classes.icon} />
                        <h1 className={classes.title}>{t`Affiliate Portal`}</h1>
                        <Text c="dimmed" size="sm" className={classes.subtitle}>
                            {t`View your referral stats and earnings`}
                        </Text>
                    </div>

                    {!emailSent ? (
                        <form onSubmit={handleSubmit}>
                            <Text size="sm" mb="md">
                                {t`Enter your email address and we'll send you a magic link to access your affiliate portal.`}
                            </Text>

                            <TextInput
                                label={t`Email Address`}
                                placeholder="you@example.com"
                                size="md"
                                {...form.getInputProps('email')}
                            />

                            <Button
                                type="submit"
                                fullWidth
                                size="md"
                                mt="lg"
                                loading={mutation.isPending}
                                leftSection={<IconMail size={18} />}
                            >
                                {t`Send Magic Link`}
                            </Button>
                        </form>
                    ) : (
                        <div className={classes.successMessage}>
                            <IconCheck size={48} className={classes.successIcon} />
                            <Text size="lg" fw={500} mt="md">
                                {t`Check your inbox!`}
                            </Text>
                            <Text size="sm" c="dimmed" mt="xs">
                                {t`If you have an affiliate account with this email, we've sent you a magic link. Click the link in the email to access your portal.`}
                            </Text>
                            <Button
                                variant="subtle"
                                mt="lg"
                                onClick={() => {
                                    setEmailSent(false);
                                    form.reset();
                                }}
                            >
                                {t`Send another link`}
                            </Button>
                        </div>
                    )}
                </Card>

                <PoweredByFooter />
            </div>
        </CheckoutContent>
    );
};

export default AffiliateLogin;
