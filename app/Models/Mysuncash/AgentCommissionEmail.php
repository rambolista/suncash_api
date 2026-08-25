<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A notification e-mail address for a merchant's agent-commission alerts. */
#[Fillable(['client_record_id', 'email', 'status'])]
class AgentCommissionEmail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'agent_commission_email';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
