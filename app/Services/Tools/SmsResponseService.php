<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\SmsResponse;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > SMS Responses" (legacy `tools/sms_responses` +
 * `tools_model::get_sms_definition()`/`save_or_create_sms_response()`). A
 * merchant-scoped dictionary of canned outbound SMS reply templates (e.g.
 * "Invalid PIN Code. Please check and try again.") — despite the menu
 * name, this is NOT an inbound-message inbox; legacy has no webhook/cron
 * anywhere that receives customer replies into this table. It's a small,
 * static, edit-only template list (50 rows in production, unchanged since
 * 2012) presumably read by a separate SMS-gateway component outside this
 * codebase when it composes automated replies.
 *
 * `merchant_id = 0` rows are the "All Merchants" baseline; a row with the
 * same `response_title` under a specific merchant overrides it for that
 * merchant only. Legacy's list always shows BOTH — baseline rows plus that
 * merchant's overrides layered on top, keyed by title so an override
 * replaces (not duplicates) its baseline row.
 */
class SmsResponseService
{
    /**
     * Legacy `tools_model::get_merchant_list()` + the "- SELECT -"/"All
     * Merchants" options prepended in the controller. Filtered to active,
     * named merchants only (same `registration_status = 'A'` filter this
     * codebase's other merchant pickers already settled on) — the
     * unfiltered table has 800+ rows, many blank/legacy test data.
     */
    public function merchants(): array
    {
        $merchants = Merchant::where('registration_status', 'A')
            ->whereNotNull('merchant_name')->where('merchant_name', '!=', '')
            ->orderBy('merchant_name')
            ->get(['id', 'merchant_name'])
            ->map(fn (Merchant $m) => ['id' => (string) $m->id, 'name' => $m->merchant_name])
            ->all();

        return array_merge([['id' => '0', 'name' => 'All Merchants']], $merchants);
    }

    /** Legacy `get_sms_definition($merchantId)` — baseline (merchant_id=0) rows, with this merchant's own rows overriding same-titled baseline rows. */
    public function list(string $merchantId): array
    {
        $baseline = SmsResponse::where('merchant_id', 0)->get(['id', 'response_title', 'message_template', 'merchant_id']);

        $rows = collect();
        foreach ($baseline as $row) {
            $rows[$row->response_title] = $row;
        }

        if ($merchantId !== '0') {
            $overrides = SmsResponse::where('merchant_id', $merchantId)->get(['id', 'response_title', 'message_template', 'merchant_id']);
            foreach ($overrides as $row) {
                $rows[$row->response_title] = $row;
            }
        }

        return $rows->values()->map(fn (SmsResponse $row) => [
            'id' => $row->id,
            'response_title' => $row->response_title,
            'message_template' => $row->message_template,
            'merchant_id' => (string) $row->merchant_id,
            'is_inherited' => (string) $row->merchant_id !== $merchantId,
        ])->sortBy('response_title')->values()->all();
    }

    /** @throws ValidationException */
    public function update(string $merchantId, string $responseTitle, string $content, User $actor): array
    {
        $existing = SmsResponse::where('merchant_id', $merchantId)->where('response_title', $responseTitle)->first();

        if ($existing) {
            if ($existing->message_template === $content) {
                throw ValidationException::withMessages(['message_template' => ['No changes to the template were made.']]);
            }

            $before = $existing->getAttributes();
            $existing->update(['message_template' => $content, 'last_modified' => now()]);
            ActivityLog::recordUpdated($actor, 'Tools - SMS Responses', $existing, $before, ['message_template', 'last_modified']);

            return ['id' => $existing->id, 'response_title' => $existing->response_title, 'message_template' => $existing->message_template, 'merchant_id' => (string) $existing->merchant_id, 'is_inherited' => false];
        }

        $created = SmsResponse::create([
            'merchant_id' => $merchantId,
            'response_title' => $responseTitle,
            'message_template' => $content,
            'date_created' => now(),
            'last_modified' => now(),
        ]);
        ActivityLog::recordCreated($actor, 'Tools - SMS Responses', $created, ['merchant_id', 'response_title', 'message_template']);

        return ['id' => $created->id, 'response_title' => $created->response_title, 'message_template' => $created->message_template, 'merchant_id' => (string) $created->merchant_id, 'is_inherited' => false];
    }
}
