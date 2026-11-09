<?php

use App\Models\Content\Page\InfoPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            return;
        }

        DB::transaction(function (): void {
            $page = InfoPage::query()->where('code', 'lavaggio-modx-88')->first();
            foreach ($page?->translations()->where('locale', 'hr')->get() ?? [] as $translation) {
                // Restore only the previously approved date; retain all edited prices and copy.
                $body = str_replace('Cijene vrijede od: 01.06.2026', 'Cijene vrijede od: 10.09.2026', (string) $translation->body_html);
                if ($body !== (string) $translation->body_html) {
                    $translation->update(['body_html' => $body]);
                }
            }
        });
    }

    public function down(): void
    {
        // Preserve the approved pricing date and any later editorial changes.
    }
};
