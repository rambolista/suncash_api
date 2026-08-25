<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $username, public readonly string $newPassword)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Your merchant portal password was reset')
            ->view('mail.merchant-password-reset');
    }
}
