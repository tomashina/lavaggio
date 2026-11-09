<?php

namespace App\Services\Integrations\Fira;

use App\Models\Sales\Order\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class FiraInvoiceService
{
    public function __construct(private readonly FiraSettingsService $settings, private readonly FiraOrderPayload $payloads) {}

    public function issue(int $orderId, string $paymentType, int $actorId, string $reviewedHash): void
    {
        if ($blockers = $this->settings->blockers()) {
            throw ValidationException::withMessages(['fira' => $blockers]);
        }
        $payload = DB::transaction(function () use ($orderId, $paymentType, $actorId, $reviewedHash): array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if (DB::table('lavaggio_fira_documents')->where('order_id', $orderId)->exists()) {
                throw ValidationException::withMessages(['fira' => 'Za ovu narudžbu već postoji pokušaj izrade dokumenta. Provjerite dokument u FIRA-i; ponovno slanje je blokirano. Ponudu po potrebi pretvorite u račun izravno u FIRA-i.']);
            }
            $payload = $this->payloads->build($order, $paymentType);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if (! hash_equals($hash, $reviewedHash)) {
                throw ValidationException::withMessages(['fira' => 'Podaci narudžbe ili vrsta dokumenta su promijenjeni. Ponovno otvorite pregled dokumenta.']);
            }
            DB::table('lavaggio_fira_documents')->insert([
                'order_id' => $orderId, 'requested_by' => $actorId, 'state' => 'sending',
                'document_type' => $payload['invoiceType'],
                'payload_hash' => $hash,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $payload;
        });

        // No retries or redirects: the first request can already have created the document.
        try {
            $response = Http::acceptJson()->asJson()
                ->withHeaders(['FIRA-Api-Key' => $this->settings->secret()])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(10)->timeout(50)->post(FiraSettingsService::ENDPOINT, $payload);
        } catch (\Throwable) {
            $this->record($orderId, ['state' => 'uncertain']);
            throw ValidationException::withMessages(['fira' => 'Odgovor FIRA-e nije potvrđen. Provjerite narudžbu u FIRA-i prije bilo kakvog daljnjeg izdavanja dokumenta.']);
        }

        $body = $response->json();
        $invoiceId = is_array($body) ? ($body['id'] ?? null) : null;
        if ($response->successful() && is_numeric($invoiceId) && (int) $invoiceId > 0) {
            $this->record($orderId, [
                'state' => 'created', 'http_status' => $response->status(),
                'invoice_id' => (string) $invoiceId,
                'invoice_number' => mb_substr((string) ($body['invoiceNumber'] ?? ''), 0, 128),
                'fiscal_status' => mb_substr((string) ($body['invoiceFiscalPaymentStatus'] ?? ''), 0, 64),
                'has_jir' => $payload['invoiceType'] === 'FISKALNI_RAČUN' && trim((string) ($body['jir'] ?? '')) !== '',
            ]);

            return;
        }
        // Never store/render arbitrary response text (it may contain credentials or personal data).
        $this->record($orderId, ['state' => 'uncertain', 'http_status' => $response->status()]);
        throw ValidationException::withMessages(['fira' => 'FIRA nije potvrdila izradu dokumenta. Provjerite zapis u FIRA-i. Ponovno slanje ove narudžbe je blokirano radi zaštite od duplikata.']);
    }

    private function record(int $orderId, array $values): void
    {
        DB::table('lavaggio_fira_documents')->where('order_id', $orderId)->update([...$values, 'updated_at' => now()]);
    }
}
