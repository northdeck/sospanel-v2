<?php

namespace App\Northdeck\Admin;

use App\Support\Mail\BrandMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "You're invited to make an account", from an instance admin. */
class PanelInviteSent extends Notification
{
    use Queueable;

    public function __construct(public PanelInvite $invite) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return route('register', ['email' => $this->invite->email]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $panel = (string) config('app.name');
        $inviter = $this->invite->inviter->name ?? 'An admin';
        $days = max(1, (int) round(now()->diffInDays($this->invite->expires_at)));
        $line = "{$inviter} invited you to make an account on {$panel}. Sign up with {$this->invite->email}, the address this email went to.";
        $note = "The invite lasts {$days} days. If you weren’t expecting it, ignore this email and nothing changes.";

        return BrandMail::apply((new MailMessage)
            ->subject("You’re invited to {$panel}")
            ->line($line)
            ->action('Make your account', $this->url())
            ->line($note), [
                'image' => 'invite',
                'heading' => "Join {$panel}",
                'paragraphs' => [$line],
                'button' => ['label' => 'Make your account', 'url' => $this->url()],
                'note' => $note,
            ]);
    }
}
