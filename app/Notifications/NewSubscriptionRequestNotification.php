<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\SubscriptionRequest;

class NewSubscriptionRequestNotification extends Notification
{
    use Queueable;

    protected SubscriptionRequest $subscriptionRequest;

    /**
     * Crée une nouvelle instance de notification.
     */
    public function __construct(SubscriptionRequest $subscriptionRequest)
    {
        $this->subscriptionRequest = $subscriptionRequest;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification (stocké dans la colonne JSON de la table notifications).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'uuid' => $this->subscriptionRequest->uuid,
            'establishment_uuid' => $this->subscriptionRequest->establishment_uuid,
            'establishment_name' => $this->subscriptionRequest->establishment->name ?? '',
            'license_name' => $this->subscriptionRequest->license->name ?? '',
            'message' => "Nouvelle demande d'abonnement reçue pour l'établissement " . ($this->subscriptionRequest->establishment->name ?? ''),
        ];
    }
}
