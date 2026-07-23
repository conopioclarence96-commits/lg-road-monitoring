<?php

require_once __DIR__ . '/TomTomClient.php';

class NotificationsService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function sendNotification(array $notification, array $params = []): array {
        return $this->client->request('/notifications/1/send', $params, 'POST', $notification);
    }

    public function createSubscription(string $channel, string $endpoint, array $params = []): array {
        $body = [
            'channel' => $channel,
            'endpoint' => $endpoint,
            'type' => $params['type'] ?? 'webhook',
        ];
        return $this->client->request('/notifications/1/subscriptions', $params, 'POST', $body);
    }

    public function listSubscriptions(array $params = []): array {
        return $this->client->request('/notifications/1/subscriptions', $params);
    }

    public function getSubscription(string $subscriptionId, array $params = []): array {
        return $this->client->request('/notifications/1/subscriptions/' . $subscriptionId, $params);
    }

    public function deleteSubscription(string $subscriptionId, array $params = []): array {
        return $this->client->request('/notifications/1/subscriptions/' . $subscriptionId, $params, 'DELETE');
    }

    public function listChannels(array $params = []): array {
        return $this->client->request('/notifications/1/channels', $params);
    }

    public function getChannel(string $channelId, array $params = []): array {
        return $this->client->request('/notifications/1/channels/' . $channelId, $params);
    }

    public function listNotifications(array $params = []): array {
        return $this->client->request('/notifications/1/notifications', $params);
    }

    public function getNotification(string $notificationId, array $params = []): array {
        return $this->client->request('/notifications/1/notifications/' . $notificationId, $params);
    }
}
