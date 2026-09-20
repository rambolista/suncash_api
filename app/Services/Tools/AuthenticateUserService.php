<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Models\User;
use App\Services\Notifications\Sms\SmsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Management > Authenticate User" (legacy
 * `tools/authenticat_user_notif` + `get_active_authenticat_user_log`). A
 * support-triggered step-up re-verification notice sent to an already
 * active customer (PIN reset / email change / account unlock / device
 * unlink / profile update / dispute / other) — unrelated to KYC-tier
 * approval (`KycUpgradeService`).
 *
 * Legacy's own admin action is a thin relay to an external "chp" (card-
 * holder-portal) API that isn't present anywhere in either codebase — the
 * real OTP generation/delivery/verification logic lives entirely outside
 * what was ported here. This sends the notification directly instead,
 * reusing the same `SmsManager` every other SMS feature in this app
 * already uses, and Laravel's `Mail` facade for the email path. It does
 * NOT generate or track a verifiable OTP code — legacy's own admin-side
 * action never verified one either, that happens downstream in the
 * customer-facing app which this port doesn't reach.
 */
class AuthenticateUserService
{
    /** Legacy: a Pending request older than 3 minutes is lazily expired the next time it's read, not via a scheduled job. */
    private const PENDING_TTL_SECONDS = 180;

    public function __construct(private readonly SmsManager $sms) {}

    /** Legacy `get_active_authenticat_user_log()` — now with the live countdown fields the polling UI needs (`expires_at`/`remaining_seconds`). */
    public function activeRequest(int $customerId): ?array
    {
        $row = DB::connection('mysuncash')->table('force_authenticate_user_logs')
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            return null;
        }

        if ($row->status === 'Pending') {
            $elapsed = now()->diffInSeconds($row->created_at);
            if ($elapsed > self::PENDING_TTL_SECONDS) {
                DB::connection('mysuncash')->table('force_authenticate_user_logs')->where('id', $row->id)->update(['status' => 'Expired', 'updated_at' => now()]);
                $row->status = 'Expired';
            }
        }

        $data = (array) $row;
        $expiresAt = \Illuminate\Support\Carbon::parse($row->created_at)->addSeconds(self::PENDING_TTL_SECONDS);
        $data['expires_at'] = $expiresAt->toDateTimeString();
        $data['remaining_seconds'] = $row->status === 'Pending' ? max(0, (int) round(now()->diffInSeconds($expiresAt, false))) : 0;

        return $data;
    }

    /** @throws ValidationException */
    public function request(int $customerId, string $method, string $reason, ?string $reasonOther, User $actor): array
    {
        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }
        if (! in_array($method, ['sms', 'email'], true)) {
            throw ValidationException::withMessages(['method' => ['Invalid method.']]);
        }

        $reasonText = $reason === 'Other' ? trim((string) $reasonOther) : $reason;
        if ($reasonText === '') {
            throw ValidationException::withMessages(['reason' => ['Please provide a reason.']]);
        }

        $message = "SunCash: a re-verification was requested for your account (reason: {$reasonText}). If this wasn't you, please contact support immediately.";

        if ($method === 'sms') {
            if (blank($customer->mobile)) {
                throw ValidationException::withMessages(['method' => ['This customer has no mobile number on file.']]);
            }
            $this->sms->send($customer->mobile, $message);
        } else {
            if (blank($customer->email)) {
                throw ValidationException::withMessages(['method' => ['This customer has no email on file.']]);
            }
            Mail::raw($message, fn ($mail) => $mail->to($customer->email)->subject('SunCash Account Re-Verification'));
        }

        $id = DB::connection('mysuncash')->table('force_authenticate_user_logs')->insertGetId([
            'customer_id' => $customerId,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
            'requester_id' => $actor->id,
            'method' => $method,
            'reason' => $reasonText,
            'attempts' => 1,
        ]);

        ActivityLog::recordAction($actor, 'Tools - Customer Management', 'authenticate_requested', "Requested {$method} re-authentication for customer {$customerId} (reason: {$reasonText})");

        return (array) DB::connection('mysuncash')->table('force_authenticate_user_logs')->find($id);
    }
}
