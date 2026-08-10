<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VehicleReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->payload['title'])->line($this->payload['message'])->action('Open Vehicle Hub', config('app.frontend_url', config('app.url')));
    }
}
