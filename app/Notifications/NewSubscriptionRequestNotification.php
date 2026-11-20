<?php

namespace App\Notifications;

use App\Models\SubscriptionRequest;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class NewSubscriptionRequestNotification extends Notification
{
    public function __construct(protected SubscriptionRequest $subscriptionRequest)
    {
        $this->subscriptionRequest->loadMissing(['establishment', 'license']);
    }

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
        $establishment = $this->subscriptionRequest->establishment?->name ?? '';

        return [
            'title'              => "Demande d'abonnement",
            'kind'               => 'subscription_request',
            'message'            => "Nouvelle demande d'abonnement reçue pour l'établissement {$establishment}",
            'uuid'               => $this->subscriptionRequest->uuid,
            'establishment_uuid' => $this->subscriptionRequest->establishment_uuid,
            'establishment_name' => $establishment,
            'license_name'       => $this->subscriptionRequest->license?->name ?? '',
            'time'               => now()->toDateTimeString(),
        ];
    }
}
