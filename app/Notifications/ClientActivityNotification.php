<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ClientActivityNotification extends Notification
{
    use Queueable;

    /**
     * Crée une nouvelle instance de notification.
     */
    public function __construct(
        protected string $actionMessage,
        protected $client
    ) {}

    /**
     * Détermine les canaux par lesquels la notification sera envoyée.
     * Ici, on utilise 'database' pour la stocker en base de données.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * Données qui seront enregistrées au format JSON dans la table `notifications`.
     */
    public function toDatabase($notifiable): array
    {
        return [
            'message'     => $this->actionMessage,
            'client_uuid' => $this->client->uuid ?? null,
            'client_name' => $this->client->full_name ?? null,
            'time'        => now()->toDateTimeString(),
        ];
    }
}
