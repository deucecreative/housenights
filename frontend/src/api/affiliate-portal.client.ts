import { api } from './client.ts';
import { GenericDataResponse } from "../types.ts";

export interface AffiliatePortalData {
    affiliate: {
        id: number;
        name: string;
        code: string;
        total_orders: number;
        total_tickets: number;
        total_sales_gross: number;
    };
    event: {
        id: number;
        title: string;
        slug: string;
        currency: string;
        affiliate_term?: string;
    };
    orders: AffiliateOrder[];
}

export interface AffiliateOrder {
    id: number;
    short_id: string;
    buyer_name: string;
    total_gross: number;
    created_at: string;
}

export const affiliatePortalClient = {
    getAffiliateByToken: async (token: string) => {
        const response = await api.get<GenericDataResponse<AffiliatePortalData>>(`public/affiliate/${token}`);
        return response.data;
    },

    sendMagicLink: async (eventId: number, affiliateId: number) => {
        const response = await api.post<GenericDataResponse<{ message: string }>>(
            `events/${eventId}/affiliates/${affiliateId}/magic-link`
        );
        return response.data;
    },

    requestMagicLink: async (email: string) => {
        const response = await api.post<GenericDataResponse<{ message: string }>>(
            'public/affiliate/request-magic-link',
            { email }
        );
        return response.data;
    },
}
