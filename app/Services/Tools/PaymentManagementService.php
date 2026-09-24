<?php

namespace App\Services\Tools;

use App\Models\Mysuncash\WebLog;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Payment Management" (legacy `tools::payment_management()` +
 * `payment_management_model.php`) — a manual-payment queue with a status
 * workflow (Draft -> Pending -> Verified -> Approved -> Processing -> Paid,
 * with Return/Reject/Cancel/Retry side branches) and a permanent
 * `payment_audit_trail` history per payment.
 *
 * `mark_paid`/`mark_failed`/`delete` exist in legacy's own action map but no
 * button ever triggers them (verified against the live admin view) — not
 * ported, since PAID/FAILED are reached by some other process outside this
 * screen, not a button here.
 */
class PaymentManagementService
{
    public const COLUMNS = [
        ['key' => 'transaction_date', 'label' => 'Date/Time'],
        ['key' => 'transaction_id', 'label' => 'Transaction ID'],
        ['key' => 'payment_type', 'label' => 'Payment Type'],
        ['key' => 'company_name', 'label' => 'Company Name'],
        ['key' => 'payment_method', 'label' => 'Payment Method'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'settlement_period', 'label' => 'Settlement Period'],
        ['key' => 'status_label', 'label' => 'Status'],
    ];

    private const STATUSES = ['DRAFT', 'PENDING', 'RETURNED', 'VERIFIED', 'APPROVED', 'PROCESSING', 'PAID', 'FAILED', 'REJECTED', 'CANCELLED'];

    private const STATUS_LABELS = [
        'PENDING' => 'Pending Verification',
    ];

    /** action => [from statuses this action is valid on, the status it moves to, whether a reason is required, the permission flag that gates it]. */
    public const ACTIONS = [
        'submit' => ['from' => ['DRAFT'], 'to' => 'PENDING', 'reason' => false, 'permission' => 'can_execute'],
        'verify' => ['from' => ['PENDING'], 'to' => 'VERIFIED', 'reason' => false, 'permission' => 'can_execute'],
        'return' => ['from' => ['PENDING'], 'to' => 'RETURNED', 'reason' => true, 'permission' => 'can_cancel'],
        'resubmit' => ['from' => ['RETURNED'], 'to' => 'PENDING', 'reason' => false, 'permission' => 'can_execute'],
        'approve' => ['from' => ['VERIFIED'], 'to' => 'APPROVED', 'reason' => false, 'permission' => 'can_approve'],
        'reject' => ['from' => ['VERIFIED'], 'to' => 'REJECTED', 'reason' => true, 'permission' => 'can_cancel'],
        'process' => ['from' => ['APPROVED'], 'to' => 'PROCESSING', 'reason' => false, 'permission' => 'can_execute'],
        'retry' => ['from' => ['FAILED'], 'to' => 'PROCESSING', 'reason' => false, 'permission' => 'can_execute'],
        'cancel' => ['from' => ['DRAFT', 'PENDING', 'RETURNED', 'APPROVED', 'FAILED'], 'to' => 'CANCELLED', 'reason' => true, 'permission' => 'can_cancel'],
    ];

    private const EVENT_MAP = [
        'PENDING' => 'SUBMITTED', 'RETURNED' => 'RETURNED', 'VERIFIED' => 'VERIFIED',
        'APPROVED' => 'APPROVED', 'PROCESSING' => 'PROCESSING', 'REJECTED' => 'REJECTED',
        'CANCELLED' => 'CANCELLED', 'PAID' => 'PAID', 'FAILED' => 'FAILED',
    ];

    private const SUMMARY_STATUSES = ['PENDING' => 'Pending Verification', 'VERIFIED' => 'Verified', 'APPROVED' => 'Approved', 'PAID' => 'Paid Today', 'FAILED' => 'Failed'];

    public function listTypes(): array
    {
        return DB::connection('mysuncash')->table('payment_types')->where('status', 'A')->orderBy('name')->get(['id', 'name'])->all();
    }

    public function listMethods(): array
    {
        return DB::connection('mysuncash')->table('payment_methods')->where('status', 'A')->orderBy('name')->get(['id', 'name'])->all();
    }

    public function listClients(): array
    {
        return DB::connection('mysuncash')->table('clients')
            ->where('registration_status', 'A')
            ->selectRaw("id AS client_record_id, CASE WHEN dba_name IS NULL OR dba_name = '' THEN legal_name ELSE dba_name END AS company_name")
            ->orderBy('company_name')
            ->get()->all();
    }

    /** Legacy `get_payment_list($status)` — global (unfiltered by the list's own search filters) snapshot for the summary cards. */
    public function summary(): array
    {
        $out = [];
        foreach (self::SUMMARY_STATUSES as $status => $label) {
            $query = DB::connection('mysuncash')->table('payment_requests')->where('status', $status);
            if ($status === 'PAID') {
                $query->whereExists(function ($q) {
                    $q->select(DB::raw(1))->from('payment_audit_trail as a')
                        ->whereColumn('a.payment_id', 'payment_requests.id')
                        ->where('a.event_type', 'PAID')
                        ->whereRaw('DATE(a.created_date) = CURDATE()');
                });
            }
            $row = $query->selectRaw('COUNT(*) as trx_count, COALESCE(SUM(amount), 0) as total_amount')->first();
            $out[] = ['status' => $status, 'label' => $label, 'count' => (int) $row->trx_count, 'amount' => (float) $row->total_amount];
        }

        return $out;
    }

    private function baseQuery(): Builder
    {
        return DB::connection('mysuncash')->table('payment_requests as p')
            ->join('payment_types as t', 't.id', '=', 'p.payment_type_id')
            ->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id');
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = $this->baseQuery();

        if (filled($filters['date_from'] ?? null)) {
            $query->where('p.created_date', '>=', $filters['date_from'].' 00:00:00');
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('p.created_date', '<=', $filters['date_to'].' 23:59:59');
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('p.status', $filters['status']);
        }
        if (filled($filters['payment_type_id'] ?? null)) {
            $query->where('p.payment_type_id', $filters['payment_type_id']);
        }
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('p.transaction_id', 'like', "%{$search}%")->orWhere('p.company_name', 'like', "%{$search}%"));
        }
        if (filled($filters['settlement_period'] ?? null)) {
            $period = $filters['settlement_period'];
            $query->where(fn ($q) => $q->where('p.settlement_start', 'like', "%{$period}%")->orWhere('p.settlement_end', 'like', "%{$period}%"));
        }

        return $query;
    }

    private function present(object $row): array
    {
        return [
            'id' => $row->id,
            'transaction_id' => $row->transaction_id,
            'transaction_date' => $row->created_date,
            'payment_type_id' => $row->payment_type_id,
            'payment_type' => $row->payment_type,
            'company_name' => $row->company_name,
            'payment_method' => $row->payment_method,
            'amount' => number_format((float) $row->amount, 2),
            'settlement_period' => "{$row->settlement_start} - {$row->settlement_end}",
            'status' => $row->status,
            'status_label' => self::STATUS_LABELS[$row->status] ?? ucwords(strtolower($row->status)),
            'source' => $row->source,
        ];
    }

    public function list(array $filters): array
    {
        return $this->filteredQuery($filters)
            ->select(['p.id', 'p.transaction_id', 'p.created_date', 'p.payment_type_id', 't.name as payment_type', 'p.company_name', 'm.name as payment_method', 'p.amount', 'p.settlement_start', 'p.settlement_end', 'p.status', 'p.source'])
            ->orderByDesc('p.id')
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    /** Trend (last N days) + payment-type breakdown backing the "Payment Statistics" dashboard widget. */
    public function dashboard(string $dateFrom, string $dateTo): array
    {
        $totals = [];
        foreach (self::STATUSES as $status) {
            $row = DB::connection('mysuncash')->table('payment_requests')
                ->where('status', $status)
                ->whereBetween('created_date', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
                ->first();
            $totals[$status] = ['count' => (int) $row->count, 'amount' => (float) $row->amount];
        }

        $days = DB::connection('mysuncash')->table('payment_requests')
            ->whereBetween('created_date', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->selectRaw('DATE(created_date) as day, status, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('day', 'status')
            ->get();

        $trend = [];
        $cursor = \Carbon\Carbon::parse($dateFrom);
        $end = \Carbon\Carbon::parse($dateTo);
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $trend[$key] = ['date' => $cursor->format('d M'), 'paid_amount' => 0.0, 'pending_count' => 0, 'approved_count' => 0];
            $cursor->addDay();
        }
        foreach ($days as $d) {
            if (! isset($trend[$d->day])) continue;
            if ($d->status === 'PAID') $trend[$d->day]['paid_amount'] += (float) $d->amount;
            if ($d->status === 'PENDING') $trend[$d->day]['pending_count'] += (int) $d->count;
            if ($d->status === 'APPROVED') $trend[$d->day]['approved_count'] += (int) $d->count;
        }

        $byType = DB::connection('mysuncash')->table('payment_requests as p')
            ->join('payment_types as t', 't.id', '=', 'p.payment_type_id')
            ->whereBetween('p.created_date', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->selectRaw('t.name, COALESCE(SUM(p.amount), 0) as amount')
            ->groupBy('t.name')
            ->orderByDesc('amount')
            ->limit(4)
            ->get();
        $typeTotal = $byType->sum('amount') ?: 1;

        return [
            'totals' => $totals,
            'trend' => array_values($trend),
            'by_type' => $byType->map(fn ($t) => [
                'name' => $t->name,
                'amount' => (float) $t->amount,
                'percentage' => round(($t->amount / $typeTotal) * 100),
            ])->all(),
        ];
    }

    public function details(int $id): array
    {
        $row = $this->baseQuery()->where('p.id', $id)->select(['p.*', 't.name as payment_type', 'm.name as payment_method'])->first();
        if (! $row) {
            throw ValidationException::withMessages(['id' => ['Payment not found.']]);
        }

        $doc = DB::connection('mysuncash')->table('payment_documents')
            ->where('payment_id', $id)->where('is_active', 1)->orderByDesc('id')->first();

        return [
            ...$this->present($row),
            'external_reference' => $row->external_reference,
            'prepared_by' => $row->prepared_by,
            'notes' => $row->notes,
            'merchant_id' => $row->merchant_id,
            'payment_method_id' => $row->payment_method_id,
            'settlement_start' => $row->settlement_start,
            'settlement_end' => $row->settlement_end,
            'supporting_doc_name' => $doc->file_name ?? null,
            'supporting_doc_url' => $doc ? $this->documentUrl($doc->file_url) : null,
        ];
    }

    private function documentUrl(?string $marker): ?string
    {
        if (blank($marker)) {
            return null;
        }
        if (preg_match('#^https?://#i', $marker)) {
            return $marker;
        }

        $prefix = config('filesystems.disks.s3.bucket').'/'.config('filesystems.disks.s3.root').'/';
        if (! str_starts_with($marker, $prefix)) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl(substr($marker, strlen($prefix)), now()->addMinutes(20));
    }

    private function storeDocument(int $paymentId, string $base64, ?string $fileName): void
    {
        $ext = ($fileName && str_contains($fileName, '.')) ? strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) : '';
        $key = "payment-management/{$paymentId}/".now()->timestamp.'_'.Str::random(8).($ext ? ".{$ext}" : '');
        Storage::disk('s3')->put($key, base64_decode($base64));
        $marker = config('filesystems.disks.s3.bucket').'/'.config('filesystems.disks.s3.root').'/'.$key;

        DB::connection('mysuncash')->table('payment_documents')->where('payment_id', $paymentId)->where('is_active', 1)->update(['is_active' => 0]);
        DB::connection('mysuncash')->table('payment_documents')->insert([
            'payment_id' => $paymentId, 'file_url' => $marker, 'file_name' => $fileName, 'file_type' => $ext, 'is_active' => 1, 'created_date' => now(),
        ]);
    }

    /** Legacy `audit_labels()` — swaps `payment_type_id`/`payment_method_id` for their human-readable names before they're written to the audit trail. */
    private function auditLabels(array $values): array
    {
        $map = ['payment_type_id' => 'payment_types', 'payment_method_id' => 'payment_methods'];
        foreach ($map as $key => $table) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $values[str_replace('_id', '', $key)] = DB::connection('mysuncash')->table($table)->where('id', $values[$key])->value('name');
            unset($values[$key]);
        }

        return $values;
    }

    private function logAudit(int $paymentId, string $eventType, ?array $oldValues, ?array $newValues, ?string $reason, User $actor): void
    {
        DB::connection('mysuncash')->table('payment_audit_trail')->insert([
            'payment_id' => $paymentId,
            'event_type' => $eventType,
            'user_id' => (string) $actor->id,
            'user_name' => $actor->name ?? $actor->email,
            'source_ip' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
            'old_values' => $oldValues === null ? null : json_encode($oldValues),
            'new_values' => $newValues === null ? null : json_encode($newValues),
            'reason' => $reason,
            'created_date' => now(),
        ]);
    }

    private function logWeb(string $logType, string $data, User $actor): void
    {
        WebLog::create([
            'customer_id' => -1,
            'user_id' => $actor->id,
            'updated_by' => $actor->name ?? $actor->email,
            'data' => $data,
            'log_type' => $logType,
            'user_ip_address' => request()->ip(),
            'web_channel' => 'admin',
        ]);
    }

    private function validatePaymentFields(array $data): array
    {
        return validator($data, [
            'payment_type_id' => ['required', 'integer'],
            'company_name' => ['required', 'string', 'max:250'],
            'client_record_id' => ['nullable', 'integer'],
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'settlement_start' => ['required', 'date'],
            'settlement_end' => ['required', 'date', 'after_or_equal:settlement_start'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'prepared_by' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ])->validate();
    }

    /** @throws ValidationException */
    public function addManualPayment(array $data, User $actor): array
    {
        $fields = $this->validatePaymentFields($data);
        $status = in_array($data['status'] ?? null, ['DRAFT', 'PENDING'], true) ? $data['status'] : 'PENDING';
        if (blank($data['document'] ?? null)) {
            throw ValidationException::withMessages(['document' => ['A supporting document is required.']]);
        }

        $transactionId = 'M'.substr((string) (int) (microtime(true) * 1000), -7);

        $insert = [
            'transaction_id' => $transactionId,
            'payment_type_id' => $fields['payment_type_id'],
            'merchant_id' => $fields['client_record_id'] ?? null,
            'company_name' => $fields['company_name'],
            'payment_method_id' => $fields['payment_method_id'],
            'amount' => $fields['amount'],
            'settlement_start' => $fields['settlement_start'],
            'settlement_end' => $fields['settlement_end'],
            'external_reference' => $fields['external_reference'] ?? null,
            'prepared_by' => $fields['prepared_by'],
            'notes' => $fields['notes'] ?? null,
            'status' => $status,
            'created_date' => now(),
        ];

        $id = DB::connection('mysuncash')->table('payment_requests')->insertGetId($insert);
        $this->storeDocument($id, $data['document'], $data['document_name'] ?? null);
        $this->logWeb('MANUAL_PAYMENT', "SUBMITTED - {$id}", $actor);
        $this->logAudit($id, $status === 'DRAFT' ? 'CREATED_DRAFT' : 'SUBMITTED', null, $this->auditLabels($insert), null, $actor);

        return ['message' => $status === 'DRAFT' ? 'Manual payment saved as draft.' : 'Manual payment submitted for verification.', 'id' => $id];
    }

    /** @throws ValidationException */
    public function editManualPayment(int $id, array $data, User $actor): array
    {
        $current = DB::connection('mysuncash')->table('payment_requests')->where('id', $id)->first();
        if (! $current) {
            throw ValidationException::withMessages(['id' => ['Payment not found.']]);
        }
        if (! in_array($current->status, ['DRAFT', 'RETURNED'], true)) {
            throw ValidationException::withMessages(['status' => ["A payment in {$current->status} status cannot be edited."]]);
        }

        $fields = $this->validatePaymentFields($data);
        $before = (array) $current;

        $update = [
            'payment_type_id' => $fields['payment_type_id'],
            'merchant_id' => $fields['client_record_id'] ?? null,
            'company_name' => $fields['company_name'],
            'payment_method_id' => $fields['payment_method_id'],
            'amount' => $fields['amount'],
            'settlement_start' => $fields['settlement_start'],
            'settlement_end' => $fields['settlement_end'],
            'external_reference' => $fields['external_reference'] ?? null,
            'prepared_by' => $fields['prepared_by'],
            'notes' => $fields['notes'] ?? null,
            'updated_date' => now(),
        ];

        DB::connection('mysuncash')->table('payment_requests')->where('id', $id)->update($update);
        if (filled($data['document'] ?? null)) {
            $this->storeDocument($id, $data['document'], $data['document_name'] ?? null);
        }

        $this->logWeb('EDIT_MANUAL_PAYMENT', "EDITED - {$id}", $actor);
        $this->logAudit($id, 'EDITED', $this->auditLabels($before), $this->auditLabels($update), null, $actor);

        return ['message' => 'Manual payment has been updated.'];
    }

    /** @throws ValidationException */
    public function updateStatus(int $id, string $action, ?string $reason, User $actor): array
    {
        if (! isset(self::ACTIONS[$action])) {
            throw ValidationException::withMessages(['action' => ['Unknown action.']]);
        }
        $rule = self::ACTIONS[$action];

        if ($rule['reason'] && blank($reason)) {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        $current = DB::connection('mysuncash')->table('payment_requests')->where('id', $id)->first();
        if (! $current) {
            throw ValidationException::withMessages(['id' => ['Payment not found.']]);
        }
        if (! in_array($current->status, $rule['from'], true)) {
            throw ValidationException::withMessages(['status' => ["This action cannot be performed on a payment in {$current->status} status."]]);
        }

        $toStatus = $rule['to'];

        if ($toStatus === 'PROCESSING') {
            $row = $this->baseQuery()->where('p.id', $id)->select(['p.id', 'p.merchant_id', 'p.company_name', 'p.amount', 'm.name as payment_method'])->first();
            DB::connection('mysuncash')->table('manual_settlement')->insert([
                'client_record_id' => $row->merchant_id,
                'type' => $row->payment_method,
                'payee' => $row->company_name,
                'amount' => $row->amount,
                'created_by' => $actor->id,
                'created_date' => now(),
                'withdrawal_type' => 'WF',
                'account_type' => '',
                'first_withdrawal' => 'Yes',
                'channel' => 'payment_management',
                'payment_id' => $row->id,
            ]);
        }

        $affected = DB::connection('mysuncash')->table('payment_requests')
            ->where('id', $id)->where('status', $current->status)
            ->update(['status' => $toStatus, 'updated_date' => now()]);

        if (! $affected) {
            throw ValidationException::withMessages(['status' => ['Unable to update — this payment status may have just changed.']]);
        }

        $this->logWeb('UPDATE_MANUAL_PAYMENT', "{$toStatus} id:{$id}", $actor);
        $this->logAudit($id, self::EVENT_MAP[$toStatus] ?? "STATUS_{$toStatus}", ['status' => $current->status], ['status' => $toStatus], $reason, $actor);

        return ['message' => 'Manual payment has been updated.', 'status' => $toStatus];
    }

    /** Legacy `redraft_payment()` — the "Save as Draft" action shown on a REJECTED row. @throws ValidationException */
    public function cloneToDraft(int $id, User $actor): array
    {
        $source = DB::connection('mysuncash')->table('payment_requests')->where('id', $id)->first();
        if (! $source) {
            throw ValidationException::withMessages(['id' => ['Source payment not found.']]);
        }

        $transactionId = 'M'.substr((string) (int) (microtime(true) * 1000), -7);
        $insert = [
            'transaction_id' => $transactionId,
            'payment_type_id' => $source->payment_type_id,
            'merchant_id' => $source->merchant_id,
            'company_name' => $source->company_name,
            'payment_method_id' => $source->payment_method_id,
            'amount' => $source->amount,
            'currency' => $source->currency,
            'settlement_start' => $source->settlement_start,
            'settlement_end' => $source->settlement_end,
            'external_reference' => $source->external_reference,
            'source' => $source->source,
            'prepared_by' => $source->prepared_by,
            'notes' => $source->notes,
            'bank_reference' => $source->bank_reference,
            'status' => 'DRAFT',
            'created_date' => now(),
        ];

        $newId = DB::connection('mysuncash')->table('payment_requests')->insertGetId($insert);

        $sourceDoc = DB::connection('mysuncash')->table('payment_documents')->where('payment_id', $id)->where('is_active', 1)->orderByDesc('id')->first();
        if ($sourceDoc) {
            DB::connection('mysuncash')->table('payment_documents')->insert([
                'payment_id' => $newId, 'file_url' => $sourceDoc->file_url, 'file_name' => $sourceDoc->file_name, 'file_type' => $sourceDoc->file_type, 'is_active' => 1, 'created_date' => now(),
            ]);
        }

        $this->logWeb('UPDATE_MANUAL_PAYMENT', "DRAFT - {$newId}", $actor);
        $this->logAudit($newId, 'DRAFT_FROM_REJECTED', ['source_payment_id' => $transactionId], $this->auditLabels($insert), null, $actor);

        return ['message' => 'A new draft has been created from the rejected payment.', 'id' => $newId];
    }

    /** @throws ValidationException */
    public function history(int $id): array
    {
        $head = $this->baseQuery()->where('p.id', $id)->select(['p.id', 'p.transaction_id', 'p.company_name', 'p.amount', 'p.currency', 'p.status', 't.name as payment_type', 'm.name as payment_method'])->first();
        if (! $head) {
            throw ValidationException::withMessages(['id' => ['Payment not found.']]);
        }

        $timeline = DB::connection('mysuncash')->table('payment_audit_trail')->where('payment_id', $id)->orderBy('id')->get()
            ->map(fn ($r) => [
                'event' => $r->event_type,
                'actor' => $r->user_name ?: $r->user_id,
                'date' => $r->created_date,
                'source_ip' => $r->source_ip,
                'user_agent' => $r->user_agent,
                'old_values' => $r->old_values ? json_decode($r->old_values, true) : null,
                'new_values' => $r->new_values ? json_decode($r->new_values, true) : null,
                'reason' => $r->reason,
            ])->all();

        return ['payment' => (array) $head, 'timeline' => $timeline];
    }

    public const HISTORY_COLUMNS = [
        ['key' => 'event', 'label' => 'Event'],
        ['key' => 'actor', 'label' => 'Performed By'],
        ['key' => 'date', 'label' => 'Date'],
        ['key' => 'source_ip', 'label' => 'Source IP'],
        ['key' => 'user_agent', 'label' => 'User Agent'],
        ['key' => 'old_values', 'label' => 'Old Values'],
        ['key' => 'new_values', 'label' => 'New Values'],
        ['key' => 'reason', 'label' => 'Reason'],
    ];

    public function historyExportRows(int $id): array
    {
        $timeline = $this->history($id)['timeline'];

        return array_map(fn ($row) => [
            ...$row,
            'old_values' => $row['old_values'] ? json_encode($row['old_values']) : '',
            'new_values' => $row['new_values'] ? json_encode($row['new_values']) : '',
        ], $timeline);
    }
}
