<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Management > View ComplyAdvantage Profile" (legacy
 * `tools/view_comply_result` + `libraries/Comply.php`) — an AML/sanctions
 * screening lookup against the third-party ComplyAdvantage API. A customer
 * is only ever screened once: `compliance_workflow_instance_identifier`
 * caches the workflow id from that first screen, and every later view just
 * re-fetches that same workflow's current status.
 *
 * Gated behind `services.comply_advantage.enabled` (default false), same
 * pattern as the SMS gateways — legacy's own COMPLY_* credentials aren't
 * present anywhere in its checked-in source, so this can't call the real
 * API until real ones are supplied. While disabled, this still shows any
 * already-cached workflow id from this DB's real legacy history (33 rows
 * exist as of this port) but reports the live status as unavailable
 * instead of silently faking a result.
 */
class ComplyAdvantageService
{
    /** @throws ValidationException */
    public function getProfile(int $customerId): array
    {
        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }

        $cached = DB::connection('mysuncash')->table('compliance_workflow_instance_identifier')
            ->where('customer_id', $customerId)
            ->first();

        $base = [
            'first_name' => $customer->first_name,
            'middle_name' => $customer->middle_name,
            'last_name' => $customer->last_name,
            'workflow_instance_identifier' => $cached?->workflow_instance_identifier,
            'configured' => (bool) config('services.comply_advantage.enabled'),
        ];

        if (! config('services.comply_advantage.enabled')) {
            $base['status'] = null;
            $base['message'] = $cached
                ? 'ComplyAdvantage API credentials are not configured in this environment — showing the cached workflow id only, live status could not be fetched.'
                : 'ComplyAdvantage API credentials are not configured in this environment — this customer has not been screened yet.';

            return $base;
        }

        $workflow = $cached
            ? $this->fetchWorkflow($cached->workflow_instance_identifier)
            : $this->createAndScreen($customer);

        if ($workflow['success'] && ! $cached) {
            DB::connection('mysuncash')->table('compliance_workflow_instance_identifier')->insert([
                'customer_id' => $customerId,
                'workflow_instance_identifier' => $workflow['data']['workflow_instance_identifier'] ?? null,
                'create_date' => now(),
                'type' => 'personal',
                'uuid' => $customerId,
            ]);
        }

        if (! $workflow['success']) {
            DB::connection('mysuncash')->table('comply_error_logs')->insert([
                'customer_id' => $customerId,
                'workflow_instance_identifier' => $cached?->workflow_instance_identifier,
                'error_message' => $workflow['error'] ?? 'Unknown error',
                'error_context' => json_encode($workflow),
            ]);
        }

        return array_merge($base, [
            'status' => $workflow['data']['status'] ?? null,
            'step_details' => $workflow['data']['step_details'] ?? null,
            'message' => $workflow['success'] ? null : ($workflow['error'] ?? 'Failed to reach ComplyAdvantage.'),
        ]);
    }

    private function login(): ?string
    {
        $response = Http::asForm()->post(rtrim((string) config('services.comply_advantage.api_url'), '/').'/v2/token', [
            'username' => config('services.comply_advantage.username'),
            'realm' => config('services.comply_advantage.realm'),
            'password' => config('services.comply_advantage.password'),
        ]);

        if (! $response->successful()) {
            Log::warning('ComplyAdvantage login failed.', ['status' => $response->status()]);

            return null;
        }

        return $response->json('access_token');
    }

    private function fetchWorkflow(string $workflowId): array
    {
        $token = $this->login();
        if (! $token) {
            return ['success' => false, 'error' => 'Unable to authenticate with ComplyAdvantage.'];
        }

        $response = Http::withToken($token)->get(rtrim((string) config('services.comply_advantage.api_url'), '/')."/v2/workflows/{$workflowId}");

        return $response->successful()
            ? ['success' => true, 'data' => $response->json()]
            : ['success' => false, 'error' => 'ComplyAdvantage returned status '.$response->status()];
    }

    private function createAndScreen(Customer $customer): array
    {
        $token = $this->login();
        if (! $token) {
            return ['success' => false, 'error' => 'Unable to authenticate with ComplyAdvantage.'];
        }

        $dob = $customer->birthday ? date_parse($customer->birthday) : null;
        $externalIdentifier = $customer->id.$customer->first_name.$customer->last_name;

        $response = Http::withToken($token)->post(rtrim((string) config('services.comply_advantage.api_url'), '/').'/v2/workflows/sync/create-and-screen', [
            'customer' => [
                'external_identifier' => $externalIdentifier,
                'person' => [
                    'first_name' => $customer->first_name,
                    'middle_name' => $customer->middle_name,
                    'last_name' => $customer->last_name,
                    'gender' => $customer->gender,
                    'date_of_birth' => $dob && $dob['year'] ? sprintf('%04d-%02d-%02d', $dob['year'], $dob['month'], $dob['day']) : null,
                ],
            ],
        ]);

        return $response->successful()
            ? ['success' => true, 'data' => $response->json()]
            : ['success' => false, 'error' => 'ComplyAdvantage returned status '.$response->status()];
    }
}
