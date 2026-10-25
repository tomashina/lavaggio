<?php

namespace App\Services\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Settings\Local\PaymentMethod;
use App\Services\Integrations\Watchline\WatchlineIntegrationSettings;
use App\Services\Payments\BankTransferUpiService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\LegacyCatalogImage;
use App\Support\SearchEngineIndexing;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/** Reads configuration, public catalog metadata and local files; never contacts providers. */
class WatchlineLaunchPreflightService
{
    private array $directoryEntries = [];

    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly WatchlineIntegrationSettings $integrations,
    ) {}

    public function inspect(string $profile = 'local', bool $includeMissingPaths = false): array
    {
        if (! in_array($profile, ['local', 'live'], true)) {
            throw new InvalidArgumentException('Choose the local or live profile.');
        }
        $checks = [];
        $check = static function (string $code, bool $passes, string $message) use (&$checks): void {
            $checks[] = ['code' => $code, 'status' => $passes ? 'ok' : 'blocked', 'message' => $message];
        };
        $local = $profile === 'local';
        $check('watchline', config('storefront.home') === 'watchline', 'Provjera je namijenjena Watchline trgovini.');
        $check('environment', $local ? ! app()->environment('production') : app()->environment('production'), 'Okruženje odgovara odabranom profilu.');
        $check('safe_mode', (bool) config('commerce.local_safe_mode') === $local, $local ? 'Lokalni sigurni način je uključen.' : 'Za live profil lokalni sigurni način mora biti isključen.');
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $check('encryption_key', is_string($key) && Encrypter::supported($key, (string) config('app.cipher', 'AES-256-CBC')), 'Ključ aplikacije odgovara šifri; njegova vrijednost se ne ispisuje.');
        $check('assets', is_file(public_path('build/manifest.json')), 'Izgrađene frontend datoteke imaju Vite manifest.');
        $check('storage', is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')), 'Radne mape su dostupne za zapisivanje; provjera ne zapisuje testne datoteke.');
        $origin = (string) config('app.url');
        $host = parse_url($origin, PHP_URL_HOST);
        if (! $local) {
            $check('https_origin', parse_url($origin, PHP_URL_SCHEME) === 'https' && in_array(strtolower((string) $host), ['watchline.hr', 'www.watchline.hr'], true)
                && parse_url($origin, PHP_URL_USER) === null && parse_url($origin, PHP_URL_PASS) === null, 'Live adresa koristi HTTPS i Watchline domenu.');
            $check('debug', ! config('app.debug'), 'Debug prikaz je isključen.');
        }

        try {
            $indexingBlocked = SearchEngineIndexing::shouldBlock(Request::create($origin))
                || (bool) preg_match('/\bnoindex\b/i', (string) $this->settings->get('store_seo_robots', 'index,follow'));
            $check('indexing', $indexingBlocked === $local, $local ? 'Indeksiranje lokalnog prikaza ostaje blokirano.' : 'Live profil očekuje javno indeksiranje; provjera ga sama ne uključuje.');
            $email = [
                'store_email_enabled' => (bool) $this->settings->get('store_email_enabled', false),
                'gift_voucher_emails_enabled' => (bool) config('gift_vouchers.emails_enabled', false),
                'stock_notification_emails_enabled' => (bool) config('stock_notifications.emails_enabled', false),
            ];
            $options = $this->integrations->options();
            $enabledSchedules = count(array_filter((array) $options['schedules'], fn ($schedule) => (bool) ($schedule['enabled'] ?? false)));
            if ($local) {
                $check('local_email', ! in_array(true, $email, true), 'Lokalne email isporuke ostaju isključene.');
                $check('local_automations', ! $options['live_enabled'] && $enabledSchedules === 0, 'Master i svi rasporedi integracija ostaju isključeni.');
            } elseif (! in_array(true, $email, true)) {
                $checks[] = ['code' => 'email_disabled', 'status' => 'warning', 'message' => 'Email isporuke još su isključene; aktivacija se provjerava zasebno.'];
            }
            $bankBarcode = $this->bankBarcodeReadiness();
            if ($bankBarcode['active']) {
                $checks[] = ['code' => 'bank_barcode_receiver', 'status' => $bankBarcode['complete'] ? 'ok' : ($local ? 'warning' : 'blocked'),
                    'message' => 'Aktivna uplata na račun treba naziv, ulicu, mjesto i IBAN primatelja za bankovni barkod; vrijednosti se ne ispisuju.'];
            }
            $media = $this->mediaInventory($includeMissingPaths);
            $check('catalog_products', $media['models']['products'] > 0, 'Aktivni Watchline katalog sadrži artikle.');
            $checks[] = ['code' => 'media_originals', 'status' => $media['missing_originals'] === 0 && $media['invalid_references'] === 0 ? 'ok' : ($local ? 'warning' : 'blocked'),
                'message' => 'Izvorne slike aktivnog kataloga moraju biti lokalne, neprazne i imati točno velika/mala slova u putanji.'];
            if ((bool) config('legacy_media.remote_only', false)) {
                $checks[] = ['code' => 'remote_only', 'status' => $local ? 'warning' : 'blocked', 'message' => 'Prisilni prikaz udaljenih naslijeđenih slika još je uključen.'];
            }
        } catch (Throwable) {
            // Query exceptions may contain private bindings or connection credentials.
            $check('public_catalog_readable', false, 'Javne podatke i postavke nije moguće provjeriti. Detalji baze nisu ispisani.');
            $media = null;
            $email = null;
            $enabledSchedules = null;
            $options = [];
            $indexingBlocked = null;
            $bankBarcode = null;
        }

        return [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile' => $profile,
            'ready' => ! collect($checks)->contains('status', 'blocked'), 'environment' => app()->environment(),
            'indexing_blocked' => $indexingBlocked, 'outbound_email_flags' => $email,
            'integration_master_enabled' => (bool) ($options['live_enabled'] ?? false), 'enabled_schedule_count' => $enabledSchedules,
            'bank_barcode' => $bankBarcode,
            'checks' => $checks, 'media' => $media,
            'scope' => 'Read-only configuration and active imported product/category/brand/blog originals. No customers, orders, provider requests, settings updates or remote image probes. Uploaded media, content HTML and widget assets are outside this original-path inventory.',
        ];
    }

    private function bankBarcodeReadiness(): array
    {
        $methods = PaymentMethod::query()->where('is_active', true)->whereIn('code', ['bank', 'bank_transfer'])->get(['settings']);
        $required = array_fill_keys(['upi_receiver_name', 'upi_receiver_street', 'upi_receiver_place', 'upi_receiver_iban'], $methods->isNotEmpty());
        $complete = $methods->isNotEmpty();
        $service = app(BankTransferUpiService::class);
        foreach ($methods as $method) {
            $snapshot = [];
            foreach ($required as $key => $configured) {
                $value = trim((string) ($method->settings[$key] ?? ''));
                $required[$key] = $configured && $value !== '';
                $snapshot[substr($key, strlen('upi_'))] = $value;
            }
            $complete = $service->hasReceiverIdentity($snapshot) && $complete;
        }

        return ['active' => $methods->isNotEmpty(), 'complete' => $complete, 'required_fields' => $required];
    }

    private function mediaInventory(bool $includeMissingPaths): array
    {
        $paths = [];
        $counts = [];
        $invalid = 0;
        $this->directoryEntries = [];
        $add = static function (mixed $source) use (&$paths, &$invalid): void {
            if ($source === null || $source === '') {
                return;
            }
            $path = LegacyCatalogImage::relativePath($source);
            if ($path === null) {
                $invalid++;
            } else {
                $paths[$path] = true;
            }
        };
        $queries = [
            'products' => Product::query()->where('is_active', true),
            'categories' => Category::query()->where('scope', Category::SCOPE_CATALOG)->currentlyVisible(),
            'manufacturers' => Manufacturer::query()->where('is_active', true),
            'blog' => BlogPost::query()->visibleOnBlog([(string) config('app.locale')]),
        ];
        foreach ($queries as $name => $query) {
            $counts[$name] = 0;
            $query->select(['id', 'payload'])->chunkById(250, function ($models) use ($name, &$counts, $add): void {
                foreach ($models as $model) {
                    $counts[$name]++;
                    $add(data_get($model->payload, 'opencart.image'));
                    if ($name === 'products') {
                        $gallery = data_get($model->payload, 'opencart.gallery_images', []);
                        foreach (is_array($gallery) ? $gallery : [] as $image) {
                            $add(is_array($image) ? ($image['image'] ?? null) : $image);
                        }
                    }
                }
            });
        }
        $root = realpath((string) config('legacy_media.local_root', public_path('image')));
        $missing = [];
        foreach (array_keys($paths) as $path) {
            if ($root === false || ! $this->hasExactLocalFile($root, $path)) {
                $missing[] = $path;
            }
        }
        sort($missing, SORT_STRING);
        $report = ['models' => $counts, 'unique_original_paths' => count($paths), 'local_originals' => count($paths) - count($missing),
            'missing_originals' => count($missing), 'invalid_references' => $invalid,
            'remote_fallback_enabled' => (bool) config('legacy_media.enabled', false), 'remote_availability_probed' => false];
        if ($includeMissingPaths) {
            $report['missing_paths'] = $missing;
        }

        return $report;
    }

    private function hasExactLocalFile(string $root, string $relative): bool
    {
        $cursor = $root;
        foreach (explode('/', $relative) as $segment) {
            if (! isset($this->directoryEntries[$cursor])) {
                $this->directoryEntries[$cursor] = array_fill_keys(@scandir($cursor) ?: [], true);
            }
            if (! isset($this->directoryEntries[$cursor][$segment])) {
                return false;
            }
            $cursor .= '/'.$segment;
        }
        $real = realpath($cursor);

        return $real !== false && str_starts_with($real, $root.'/') && is_file($real) && filesize($real) > 0;
    }
}
