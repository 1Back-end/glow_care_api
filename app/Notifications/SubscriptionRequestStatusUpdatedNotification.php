<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SubscriptionRequestStatusUpdatedNotification extends Notification
{
    use Queueable;

    protected $subscriptionRequest;
    protected $status;
    protected $reason;
    protected $otp;

    public function __construct($subscriptionRequest, $status, $reason = null, $otp = null)
    {
        $this->subscriptionRequest = $subscriptionRequest;
        $this->status = $status;
        $this->reason = $reason;
        $this->otp = $otp;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $establishmentName = $this->subscriptionRequest->establishment->name ?? "l'établissement";

        $message = match ($this->status) {
            'validated', 'VALIDATED' => "Votre demande d'abonnement pour {$establishmentName} a été approuvée. Votre code d'activation OTP est : {$this->otp}",
            'rejected' => "Votre demande d'abonnement pour {$establishmentName} a été rejetée. Motif : {$this->reason}",
            'cancelled' => "Votre demande d'abonnement pour {$establishmentName} a été annulée. Motif : {$this->reason}",
            default => "Le statut de votre demande d'abonnement pour {$establishmentName} a été mis à jour."
        };

        return [
            'subscription_request_uuid' => $this->subscriptionRequest->uuid,
            'establishment_name' => $this->subscriptionRequest->establishment->name ?? null,
            'message' => $message,
            'status' => $this->status,
            'otp' => $this->otp,
        ];
    }
}
