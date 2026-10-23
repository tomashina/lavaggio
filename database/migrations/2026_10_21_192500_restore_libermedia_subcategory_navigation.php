<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // OpenCart's `top` flag controls root navigation, not child visibility.
        $isLibermedia = DB::table('categories')->where('code', 'herrera-oc-category-638')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('category_translations')
                ->whereColumn('category_translations.category_id', 'categories.id')
                ->where('name', 'like', 'Didaktičke igračke%'))->exists();

        if ($isLibermedia) {
            DB::table('categories')->where('scope', 'catalog')->whereNotNull('parent_id')
                ->where('code', 'like', 'herrera-oc-category-%')->where('is_active', true)
                ->update(['show_in_menu' => true]);
        }
    }

    public function down(): void
    {
        // Keep restored visibility; it may have since been edited by the merchant.
    }
};
