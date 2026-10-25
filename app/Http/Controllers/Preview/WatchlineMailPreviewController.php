<?php

namespace App\Http\Controllers\Preview;

use App\Http\Controllers\Controller;
use App\Services\Preview\WatchlineMailPreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WatchlineMailPreviewController extends Controller
{
    public function __construct(private readonly WatchlineMailPreviewService $previews) {}

    public function index(Request $request): Response
    {
        abort_unless($this->previews->available(), 404);
        $samples = $this->previews->samples();
        $selected = $request->query('sample', array_key_first($samples));
        abort_unless(is_string($selected), 404);
        abort_unless(isset($samples[$selected]), 404);

        return response()->view('preview.watchline-mail', compact('samples', 'selected'))
            ->withHeaders($this->headers());
    }

    public function show(string $key): Response
    {
        return response($this->previews->render($key))->withHeaders($this->headers());
    }

    private function headers(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'none'; base-uri 'none'",
        ];
    }
}
