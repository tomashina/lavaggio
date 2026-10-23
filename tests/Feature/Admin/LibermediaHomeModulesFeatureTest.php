<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form as BlockForm;
use App\Models\Content\ContentBlock;
use App\Models\User;
use App\Services\Content\ContentBlockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LibermediaHomeModulesFeatureTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('homeModules')]
    public function test_admin_can_edit_and_save_home_module_without_losing_its_frontend_content(
        string $suffix,
        string $placement,
        string $copy,
        string $sectionClass,
        string $destination
    ): void {
        config(['app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        $code = 'libermedia-home-'.$suffix;
        $path = resource_path('views/front/content-blocks/instances/'.$code.'.blade.php');
        $this->assertFileExists($path);
        $original = File::get($path);
        $compiledPath = app('blade.compiler')->getCompiledPath($path);

        try {
            $block = ContentBlock::query()->create([
                'code' => $code,
                'name' => 'Liber Media '.$suffix,
                'type' => 'rich_text',
                'is_active' => true,
            ]);
            $block->translations()->create(['locale' => 'hr', 'title' => 'Naslov modula', 'body_html' => null]);
            $block->slots()->create([
                'placement' => $placement,
                'frontend_variant' => 'all',
                'sort_order' => 10,
                'is_active' => true,
            ]);

            $form = Livewire::actingAs($user)->test(BlockForm::class, ['blockId' => $block->id]);
            $form->assertSet('form.template_body', $original);
            $this->assertStringContainsString('<section', $form->get('form.template_body'));
            $this->assertStringContainsString($copy, $form->get('form.template_body'));

            // Exercise a real content edit, followed by the same save an editor uses.
            $editedCopy = $copy.' – uređeno u administraciji';
            $editedTemplate = str_replace($copy, $editedCopy, $original);
            $form->set('form.template_body', $editedTemplate)
                ->call('save')
                ->assertHasNoErrors()
                ->assertRedirect(route('admin.content.blocks'));

            $this->assertNull($block->fresh()->translations()->where('locale', 'hr')->firstOrFail()->body_html);
            $this->assertStringContainsString($editedCopy, File::get($path));
            Livewire::actingAs($user)->test(BlockForm::class, ['blockId' => $block->id])
                ->assertSet('form.template_body', File::get($path));

            // Render through the public placement resolver, with no database HTML fallback.
            File::delete($compiledPath);
            $html = view('components.content-placement', [
                'items' => app(ContentBlockResolver::class)->forPlacement($placement, 'hr'),
                'storeSettings' => [],
            ])->render();
            $this->assertStringContainsString('class="'.$sectionClass.'"', $html);
            $this->assertStringContainsString($editedCopy, $html);
            $this->assertStringContainsString('href="'.$destination.'"', $html);
            if ($suffix === 'benefits') {
                $this->assertStringContainsString('Zaštita kupca', $html);
                $this->assertStringContainsString('Zdravstvena provjerenost', $html);
                $this->assertStringContainsString('Držimo do sigurnosti vaše djece', $html);
                $this->assertSame(4, substr_count($html, '<strong>'));
            }
        } finally {
            // Admin saves write repository templates; always restore their exact bytes.
            File::put($path, $original);
            File::delete($compiledPath);
        }
    }

    public static function homeModules(): array
    {
        return [
            'benefits' => ['benefits', 'home.hero_benefits', 'Sigurno plaćanje', 'lm-benefits', '/page/nacin-placanja-i-dostava'],
            'campaigns' => ['campaigns', 'home.before_products', 'Pronađite svoju novu avanturu', 'lm-campaign-section', '/category/skolske-torbe-i-ruksaci'],
            'kindergarten' => ['kindergarten', 'home.after_products', 'Saznajte više', 'lm-kindergarten', 'https://namjestajzavrtice.com/'],
            'assurance' => ['assurance', 'home.bottom', 'Više igre. Manje brige.', 'lm-assurance', '/page/opci-uvjeti-koristenja'],
        ];
    }
}
