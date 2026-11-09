<?php

namespace App\Services\Integrations\Fira;

use App\Models\Sales\Order\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class FiraInvoiceService
{
    public function __construct(private readonly FiraSettingsService $settings, private readonly FiraOrderPayload $payloads) {}

    public function issue(int $orderId, string $paymentType, int $actorId, string $reviewedHash): void
    {
        $this->issueDocument($orderId, $paymentType, $actorId, $reviewedHash);
    }

    /** Called only after a verified card callback has committed its first successful payment. */
    public function issueAfterCardPayment(int $orderId, string $provider): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            return;
        }

        try {
            if (! $this->settings->autoCardInvoicesEnabled()
                || $this->settings->options()['document_type'] !== 'FISKALNI_RAČUN'
                || $this->settings->blockers() !== []) {
                return;
            }

            $this->issueDocument($orderId, 'KARTICA', null, null, $provider);
        } catch (Throwable $exception) {
            // The payment is already committed. Never expose invoice errors to the paying customer
            // or log response/exception text, which may contain the API key or customer details.
            Log::warning('Automatic Lavaggio FIRA invoice requires manual review.', [
                'order_id' => $orderId,
                'provider' => in_array($provider, ['corvuspay', 'wspay'], true) ? $provider : 'unsupported',
                'failure_type' => $exception instanceof ValidationException ? 'validation' : 'transport_or_storage',
            ]);
        }
    }

    private function issueDocument(int $orderId, string $paymentType, ?int $actorId, ?string $reviewedHash, ?string $automaticProvider = null): void
    {
        if ($blockers = $this->settings->blockers()) {
            throw ValidationException::withMessages(['fira' => $blockers]);
        }
        $payload = DB::transaction(function () use ($orderId, $paymentType, $actorId, $reviewedHash, $automaticProvider): ?array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($automaticProvider !== null && ! $this->hasConfirmedLiveCardPayment($order, $automaticProvider)) {
                return null;
            }
            if (DB::table('lavaggio_fira_documents')->where('order_id', $orderId)->exists()) {
                if ($automaticProvider !== null) {
                    return null;
                }
                throw ValidationException::withMessages(['fira' => 'Za ovu narudžbu već postoji pokušaj izrade dokumenta. Provjerite dokument u FIRA-i; ponovno slanje je blokirano. Ponudu po potrebi pretvorite u račun izravno u FIRA-i.']);
            }
            $payload = $this->payloads->build($order, $paymentType);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if ($automaticProvider === null && ! hash_equals($hash, $reviewedHash ?? '')) {
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

        if ($payload === null) {
            return;
        }

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

    private function hasConfirmedLiveCardPayment(Order $order, string $provider): bool
    {
        $codes = match ($provider) {
            'corvuspay' => ['corvus', 'corvuspay', 'corvus_pay'],
            'wspay' => ['wspay', 'ws_pay'],
            default => [],
        };
        if (! in_array(strtolower(trim((string) $order->payment_method_code)), $codes, true)
            || $order->paid_at === null
            || data_get($order->payload, $provider.'.request.mode') !== 'live'
            || data_get($order->payload, $provider.'.signature_valid') !== true
            || data_get($order->payload, $provider.'.callback_authorized') !== true
            || data_get($order->payload, $provider.'.status') !== 'approved'
            || trim((string) data_get($order->payload, $provider.'.approval_code')) === ''
            || ! empty(data_get($order->payload, $provider.'.cancel_restocked_at'))
            || ! $this->hasMatchingPaymentRequest($order, $provider)) {
            return false;
        }

        return $order->transactions()->where('provider', $provider)->where('status', 'approved')
            ->whereNotNull('processed_at')->get()->contains(function ($transaction) use ($order, $provider): bool {
                return trim((string) $transaction->transaction_ref) !== ''
                    && (int) round((float) $transaction->amount * 100) === (int) round((float) $order->grand_total * 100)
                    && strtoupper((string) $transaction->currency_code) === strtoupper((string) $order->currency_code)
                    && data_get($transaction->payload, 'signature_valid') === true
                    && ($provider !== 'corvuspay' || data_get($transaction->payload, 'callback_authorized') === true);
            });
    }

    private function hasMatchingPaymentRequest(Order $order, string $provider): bool
    {
        $request = (array) data_get($order->payload, $provider.'.request', []);
        $numberKey = $provider === 'corvuspay' ? 'order_number' : 'shopping_cart_id';
        $amountKey = $provider === 'corvuspay' ? 'amount' : 'total_amount';
        $separator = $provider === 'corvuspay' ? '.' : ',';

        return (string) ($request[$numberKey] ?? '') === (string) $order->order_number
            && (string) ($request[$amountKey] ?? '') === number_format((float) $order->grand_total, 2, $separator, '')
            && ($provider !== 'corvuspay' || strtoupper((string) ($request['currency'] ?? '')) === strtoupper((string) $order->currency_code));
    }

    private function record(int $orderId, array $values): void
    {
        DB::table('lavaggio_fira_documents')->where('order_id', $orderId)->update([...$values, 'updated_at' => now()]);
    }
}
