import { GenericDataResponse, IdParam, Webhook, WebhookLog } from "../types";
import { api } from "./client";

export interface WebhookRequest {
    url: string;
    event_types: string[];
    status: 'ENABLED' | 'PAUSED';
}

export const webhookClient = {
    get: async (eventId: IdParam, webhookId: IdParam) => {
        return await api.get<GenericDataResponse<Webhook>>(`events/${eventId}/webhooks/${webhookId}`);
    },

    create: async (eventId: IdParam, webhook: WebhookRequest) => {
        return await api.post<GenericDataResponse<Webhook>>(`events/${eventId}/webhooks`, webhook);
    },

    all: async (eventId: IdParam) => {
        return await api.get<GenericDataResponse<Webhook[]>>(`events/${eventId}/webhooks`);
    },

    logs: async (eventId: IdParam, webhookId: IdParam) => {
        return await api.get<GenericDataResponse<WebhookLog[]>>(`events/${eventId}/webhooks/${webhookId}/logs`);
    },

    delete: async (eventId: IdParam, webhookId: IdParam) => {
        return await api.delete(`events/${eventId}/webhooks/${webhookId}`);
    },

    update: async (eventId: IdParam, webhookId: IdParam, webhook: WebhookRequest) => {
        return await api.put<GenericDataResponse<Webhook>>(`events/${eventId}/webhooks/${webhookId}`, webhook);
    },

    sendTest: async (eventId: IdParam, webhookId: IdParam) => {
        return await api.post<{ data: { response_code: number; response_body: string | null; success: boolean } }>(
            `events/${eventId}/webhooks/${webhookId}/test`
        );
    },
}
