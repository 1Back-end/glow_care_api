<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AppointmentNotificationValidate extends Notification
{
    public function __construct(
        protected string $customMessage,
        protected Appointment $appointment
    ) {
        $this->appointment->loadMissing('client');
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
        return [
            'title'            => 'Rendez-vous validé',
            'kind'             => 'appointment_validated',
            'message'          => $this->customMessage,
            'appointment_uuid' => $this->appointment->uuid ?? null,
            'status'           => $this->appointment->status ?? null,
            'date_rdv'         => $this->appointment->date_rdv ?? null,
            'client_name'      => $this->appointment->client?->full_name ?? null,
            'time'             => now()->toDateTimeString(),
        ];
    }
}
