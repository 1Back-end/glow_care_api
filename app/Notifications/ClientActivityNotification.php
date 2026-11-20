<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ClientActivityNotification extends Notification
{
    public function __construct(
        protected string $actionMessage,
        protected $client
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->payload()))->onConnection('sync');
    }

    private function payload(): array
    {
        return [
            'title'       => 'Activité client',
            'kind'        => 'client_activity',
            'message'     => $this->actionMessage,
            'client_uuid' => $this->client->uuid ?? null,
            'client_name' => $this->client->full_name ?? null,
            'time'        => now()->toDateTimeString(),
        ];
    }
}
