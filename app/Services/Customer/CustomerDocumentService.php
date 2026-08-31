<?php

namespace App\Services\Customer;

use App\Models\Mysuncash\WuUploadedRequest;
use Illuminate\Validation\ValidationException;

/**
 * "Customers > Documents" (legacy `Tools::customer_docs()` /
 * `tools_model.php`'s `get_customer_docs()`/`view_customer_docs()`) — a
 * read-only log of Western Union compliance-document submissions
 * (`wu_uploaded_request`). Purely for viewing; legacy's approve/reject
 * markup and JS were dead code (commented out) and are not ported.
 *
 * Deliberately FIXED vs legacy: the detail view here is looked up by the
 * submission's OWN id. Legacy's `view_client()` only ever passed
 * `customer_id` to `view_customer_docs()`, which had no `ORDER BY` and took
 * the first matching row — so for any customer with more than one
 * submission, clicking "View" on ANY of their list rows always showed the
 * SAME (oldest) submission; the others were unreachable. Keying by the
 * row's own id makes every submission independently viewable.
 *
 * The list is scoped to rows with a matching `customers` row, matching
 * legacy's `get_customer_docs()` INNER JOIN. `wu_uploaded_request.customer_id`
 * has no FK constraint (it's a loose varchar reference), and 9 of the 33
 * live rows point at a customer_id that no longer exists (one is even
 * NULL) — legacy's join silently drops these; `whereHas()` replicates that.
 */
class CustomerDocumentService
{
    private const DOCUMENT_FIELDS = [
        'upload_job_letter' => 'Job Letter',
        'upload_proof_residence' => 'Proof of Residence',
        'upload_invoice' => 'Invoice',
        'upload_bank_statement' => 'Bank Statement',
        'upload_bank_receipt' => 'Bank Receipt',
        'upload_contract' => 'Contract',
        'upload_bill' => 'Bill',
        'upload_other_documents' => 'Other Documents',
        'upload_salary_slip' => 'Salary Slip',
        'upload_proof_of_billing' => 'Proof of Billing',
    ];

    /** Same base64-vs-URL resolution as KYC Upgrade's `resolveImage()` — legacy's `s3_model->get_img()`. */
    private function resolveImage(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        if (str_starts_with($value, 'http') || str_starts_with($value, 'data:image/')) {
            return $value;
        }

        return 'data:image/jpeg;base64,'.$value;
    }

    private function present(WuUploadedRequest $request): array
    {
        $customer = $request->customer;

        return [
            'id' => $request->id,
            'customer_id' => $request->customer_id,
            'transaction_id' => $request->transaction_id,
            'created_at' => $request->created_at,
            'name' => $customer ? trim($customer->first_name.' '.$customer->last_name) : null,
            'mobile' => $customer?->mobile,
            'email' => $customer?->email,
        ];
    }

    public function list(): array
    {
        return WuUploadedRequest::with('customer')
            ->whereHas('customer')
            ->orderBy('created_at')
            ->get()
            ->map(fn (WuUploadedRequest $request) => $this->present($request))
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function getDetail(int $id): array
    {
        $request = WuUploadedRequest::with('customer')->find($id);
        if (! $request) {
            throw ValidationException::withMessages(['id' => ['Document submission not found.']]);
        }

        $documents = [];
        foreach (self::DOCUMENT_FIELDS as $field => $label) {
            $documents[] = ['key' => $field, 'label' => $label, 'url' => $this->resolveImage($request->{$field})];
        }

        return $this->present($request) + [
            'profile_pic_url' => $this->resolveImage($request->customer?->image_url),
            'documents' => $documents,
        ];
    }
}
