<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class KioskUserPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $username, public readonly string $newPassword)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Your Kiosk user account password was reset')
            ->view('mail.kiosk-user-password-reset');
    }
}
