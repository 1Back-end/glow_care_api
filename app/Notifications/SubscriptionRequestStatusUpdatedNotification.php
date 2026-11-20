<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class SubscriptionRequestStatusUpdatedNotification extends Notification
{
    public function __construct(
        protected $subscriptionRequest,
        protected $status,
        protected $reason = null,
        protected $otp = null
    ) {
        $this->subscriptionRequest->loadMissing('establishment');
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
        $establishmentName = $this->subscriptionRequest->establishment->name ?? "l'établissement";
        $status = strtolower((string) $this->status);

        [$title, $message] = match ($status) {
            'validated' => [
                'Abonnement approuvé',
                "Votre demande d'abonnement pour {$establishmentName} a été approuvée. Votre code d'activation OTP est : {$this->otp}",
            ],
            'rejected' => [
                'Abonnement rejeté',
                "Votre demande d'abonnement pour {$establishmentName} a été rejetée. Motif : {$this->reason}",
            ],
            'cancelled' => [
                'Abonnement annulé',
                "Votre demande d'abonnement pour {$establishmentName} a été annulée. Motif : {$this->reason}",
            ],
            default => [
                'Abonnement mis à jour',
                "Le statut de votre demande d'abonnement pour {$establishmentName} a été mis à jour.",
            ],
        };

        return [
            'title'                     => $title,
            'kind'                      => 'subscription_status',
            'message'                   => $message,
            'subscription_request_uuid' => $this->subscriptionRequest->uuid,
            'establishment_name'        => $this->subscriptionRequest->establishment->name ?? null,
            'status'                    => $this->status,
            'otp'                       => $this->otp,
            'time'                      => now()->toDateTimeString(),
        ];
    }
}
