<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantSettlementDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $merchantName,
        public readonly bool $approved,
        public readonly string $noteToMerchant = '',
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->approved ? 'Suncash Settlement Request - Approved' : 'Suncash Settlement Request - Rejected')
            ->view('mail.merchant-settlement-decision');
    }
}
