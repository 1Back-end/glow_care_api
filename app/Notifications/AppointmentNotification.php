<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\Appointment;

class AppointmentNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $actionMessage,
        protected Appointment $appointment
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'message'          => $this->actionMessage,
            'appointment_uuid' => $this->appointment->uuid ?? null,
            'title'            => $this->appointment->title ?? null,
            'date_rdv'         => $this->appointment->date_rdv ?? null,
            'client_name'      => $this->appointment->client?->full_name ?? null,
            'time'             => now()->toDateTimeString(),
        ];
    }
}
