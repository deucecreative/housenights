import { useMutation } from "@tanstack/react-query";
import { affiliatePortalClient } from "../api/affiliate-portal.client.ts";

export const useSendAffiliateMagicLink = () => {
    return useMutation({
        mutationFn: ({ eventId, affiliateId }: { eventId: number, affiliateId: number }) =>
            affiliatePortalClient.sendMagicLink(eventId, affiliateId),
    });
}
