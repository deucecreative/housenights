<?php

namespace HiEvents\Jobs;

use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendMetaConversionsApiEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $orderId)
    {
    }

    public function handle(
        OrderRepositoryInterface $orderRepository,
        EventSettingsRepositoryInterface $eventSettingsRepository
    ): void {
        $order = $orderRepository->findById($this->orderId);
        
        if (!$order) {
            return;
        }

        $settings = $eventSettingsRepository->findFirstWhere([
            'event_id' => $order->getEventId(),
        ]);

        if (!$settings || !$settings->getMetaConversionsApiAccessToken() || !$settings->getMetaPixelId()) {
            return;
        }

        $userData = [];

        // Hash email
        if ($order->getEmail()) {
            $userData['em'] = [hash('sha256', strtolower(trim($order->getEmail())))];
        }

        // Hash first name
        if ($order->getFirstName()) {
            $userData['fn'] = [hash('sha256', strtolower(trim($order->getFirstName())))];
        }

        // Hash last name
        if ($order->getLastName()) {
            $userData['ln'] = [hash('sha256', strtolower(trim($order->getLastName())))];
        }

        $userData['client_ip_address'] = $_SERVER['REMOTE_ADDR'] ?? request()->ip();
        if (!$userData['client_ip_address']) {
            unset($userData['client_ip_address']);
        }

        $payload = [
            'data' => [
                [
                    'event_name' => 'Purchase',
                    'event_time' => \Carbon\Carbon::parse($order->getCreatedAt())->getTimestamp(),
                    'event_source_url' => config('app.frontend_url') . '/orders/' . $order->getShortId(),
                    'action_source' => 'website',
                    'event_id' => $order->getShortId(), // For deduplication
                    'user_data' => $userData,
                    'custom_data' => [
                        'currency' => $order->getCurrency(),
                        'value' => $order->getTotalGross(),
                    ],
                ]
            ],
            // 'test_event_code' => 'TESTXXXXX' // Can be used for testing
        ];

        $pixelId = $settings->getMetaPixelId();
        $token = $settings->getMetaConversionsApiAccessToken();

        $response = Http::post("https://graph.facebook.com/v22.0/{$pixelId}/events?access_token={$token}", $payload);

        if ($response->failed()) {
            Log::error('Meta Conversions API failed', [
                'order_id' => $this->orderId,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);
        }
    }
}
