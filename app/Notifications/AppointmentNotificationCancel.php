<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
// Retirez : use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Appointment;

class AppointmentNotificationCancel extends Notification
{
    use Queueable;

    public function __construct(
        protected string $customMessage,
        protected Appointment $appointment
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'message'          => $this->customMessage,
            'appointment_uuid' => $this->appointment->uuid ?? null,
            'status'           => $this->appointment->status ?? null,
            'date_rdv'         => $this->appointment->date_rdv ?? null,
            'reason_rejected'  => $this->appointment->reason_rejected ?? null,
            'rejected_by'      => $this->appointment->rejected_by ?? null,
            'client_name'      => $this->appointment->client?->full_name ?? null,
            'time'             => now()->toDateTimeString(),
        ];
    }
}
