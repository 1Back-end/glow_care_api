<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubscriptionRequestStatusUpdatedNotification extends Notification
{
    use Queueable;

    protected $subscriptionRequest;
    protected $status;
    protected $reason;

    public function __construct($subscriptionRequest, $status, $reason = null)
    {
        $this->subscriptionRequest = $subscriptionRequest;
        $this->status = $status;
        $this->reason = $reason;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $establishmentName = $this->subscriptionRequest->establishment->name ?? "l'établissement";

        $message = match ($this->status) {
            'validated', 'VALIDATED' => "Votre demande d'abonnement pour {$establishmentName} a été approuvée.",
            'rejected' => "Votre demande d'abonnement pour {$establishmentName} a été rejetée. Motif : {$this->reason}",
            'cancelled' => "Votre demande d'abonnement pour {$establishmentName} a été annulée. Motif : {$this->reason}",
            default => "Le statut de votre demande d'abonnement pour {$establishmentName} a été mis à jour."
        };

        return [
            'subscription_request_uuid' => $this->subscriptionRequest->uuid,
            'establishment_name' => $this->subscriptionRequest->establishment->name ?? null,
            'message' => $message,
            'status' => $this->status,
        ];
    }
}
