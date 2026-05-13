<?php

namespace HiEvents\Resources\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use Illuminate\Http\Request;

/**
 * Resource used by the public ticket-lookup endpoint. Extends the standard
 * public order resource and appends an `is_attendee_scope` flag indicating
 * whether the order is being returned in privacy-scoped mode (i.e. an
 * attendee whose email differs from the purchaser's looked it up). The
 * actual PII masking / attendee filtering happens upstream in the
 * GetOrdersByLookupTokenHandler.
 *
 * @mixin OrderDomainObject
 */
class TicketLookupOrderResource extends OrderResourcePublic
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $data['is_attendee_scope'] = (bool) ($this->resource->isAttendeeScope ?? false);

        return $data;
    }
}
