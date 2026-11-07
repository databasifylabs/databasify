<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Events\DatabaseAlertRaised;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DatabaseAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $subject,
        public string $message,
        public string $url,
    ) {}

    public static function from(DatabaseAlertRaised $alert): self
    {
        return new self($alert->subject, $alert->message, route('databases.show', ['project' => $alert->database->project_id, 'database' => $alert->database]));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->message)
            ->action('Open database', $this->url);
    }
}
