<?php

namespace HiEvents\Listeners\Order;

use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\SendMetaConversionsApiEventJob;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendMetaConversionsApiEventListener implements ShouldQueue
{
    public function __construct(private readonly EventSettingsRepositoryInterface $eventSettingsRepository)
    {
    }

    public function handle(OrderStatusChangedEvent $event): void
    {
        if (!$event->order->isOrderCompleted()) {
            return;
        }

        $settings = $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $event->order->getEventId(),
        ]);

        if (!$settings || !$settings->getMetaConversionsApiAccessToken() || !$settings->getMetaPixelId()) {
            return;
        }

        SendMetaConversionsApiEventJob::dispatch($event->order->getId());
    }
}
