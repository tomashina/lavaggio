<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Faq\Form as FaqForm;
use App\Livewire\Admin\Content\Faq\Manager;
use App\Livewire\Admin\Settings\System\StoreSettings;
use App\Models\Content\Support\Faq;
use App\Models\User;
use App\Services\Content\LavaggioContactCards;
use App\Services\Content\LavaggioFaqGroups;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioContentEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr', 'storefront.theme' => 'herrera']);
        app()->setLocale('hr');
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        $this->actingAs($admin);
    }

    public function test_contact_cards_can_be_added_edited_hidden_and_reloaded_with_shared_contact_values(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->putMany(['store_footer_phone' => '097 111 22 33', 'store_footer_hours' => 'Pon-Pet: 08h - 16h']);
        Livewire::test(StoreSettings::class)
            ->set('tab', 'branding')
            ->assertCount('form.lavaggio_contact_cards', 4)
            ->set('form.lavaggio_contact_cards.0.title', 'Nazovite naš tim')
            ->set('form.lavaggio_contact_cards.0.sort_order', 40)
            ->set('form.lavaggio_contact_cards.1.is_active', false)
            ->call('addLavaggioContactCard')
            ->set('form.lavaggio_contact_cards.4.title', 'Dostava')
            ->set('form.lavaggio_contact_cards.4.text', 'Sve informacije o dostavi.')
            ->set('form.lavaggio_contact_cards.4.url', '/stranica/dostava')
            ->set('form.lavaggio_contact_cards.4.link_label', 'Pogledajte dostavu')
            ->call('save')->assertHasNoErrors();

        Livewire::test(StoreSettings::class)->assertSet('form.lavaggio_contact_cards.0.title', 'Nazovite naš tim');
        $cards = app(LavaggioContactCards::class)->forStorefront(['phone' => '097 111 22 33', 'hours' => 'Pon-Pet: 08h - 16h']);
        $this->assertCount(4, $cards);
        $this->assertFalse($cards->contains('title', 'Kontakt mailom'));
        $this->assertSame('097 111 22 33', $cards->firstWhere('title', 'Nazovite naš tim')['link_label']);
        $this->assertSame('tel:0971112233', $cards->firstWhere('title', 'Nazovite naš tim')['href']);
        $this->assertSame('Pon-Pet: 08h - 16h', $cards->firstWhere('title', 'Nazovite naš tim')['hours']);
        $this->assertSame('Reklamacije', $cards->first()['title']);
    }

    public function test_unsafe_contact_link_is_rejected_and_an_intentionally_empty_list_stays_empty(): void
    {
        Livewire::test(StoreSettings::class)->set('tab', 'branding')
            ->set('form.lavaggio_contact_cards.3.url', 'javascript:alert(1)')
            ->call('save')->assertHasErrors('form.lavaggio_contact_cards.3.url');
        Livewire::test(StoreSettings::class)->set('tab', 'branding')
            ->set('form.lavaggio_contact_cards', [])->call('save')->assertHasNoErrors();
        $this->assertSame([], app(LavaggioContactCards::class)->editable());
    }

    public function test_faq_theme_titles_and_order_persist_and_used_themes_cannot_be_removed(): void
    {
        $faq = Faq::create(['code' => 'test', 'group_code' => 'lavaggio', 'is_active' => true]);
        $faq->translations()->create(['locale' => 'hr', 'slug' => 'pitanje', 'question' => 'Pitanje o praonici?', 'answer_html' => '<p>Odgovor.</p>']);
        Livewire::test(Manager::class)
            ->set('faqGroups.1.title', 'Sve o našoj praonici')
            ->set('faqGroups.1.sort_order', 0)
            ->call('saveGroups')->assertHasNoErrors();
        $this->assertSame('Sve o našoj praonici', collect(app(LavaggioFaqGroups::class)->all())->firstWhere('code', 'lavaggio')['title']);
        Livewire::test(Manager::class)->call('removeGroup', 1)->assertHasErrors('faqGroups');
        $this->get(route('faq.index'))->assertOk()->assertSee('Sve o našoj praonici')->assertSee('Pitanje o praonici?');
    }

    public function test_new_faq_theme_can_be_selected_on_a_question_and_renders_before_other_themes(): void
    {
        $manager = Livewire::test(Manager::class)->call('addGroup')
            ->set('faqGroups.3.title', 'Pitanja o plaćanju')
            ->set('faqGroups.3.sort_order', 0)
            ->call('saveGroups')->assertHasNoErrors();
        $code = collect(app(LavaggioFaqGroups::class)->all())->firstWhere('title', 'Pitanja o plaćanju')['code'];
        Livewire::test(FaqForm::class)->assertSee('Pitanja o plaćanju')
            ->set('form.code', 'novo-pitanje')
            ->set('form.group_code', $code)
            ->set('form.question', 'Kako mogu platiti narudžbu?')
            ->set('form.slug', 'placanje-narudzbe')
            ->set('form.answer_html', '<p>Odaberite način plaćanja u naplati.</p>')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('content_faqs', ['code' => 'novo-pitanje', 'group_code' => $code]);
        $this->get(route('faq.index'))->assertOk()->assertSee('Pitanja o plaćanju')->assertSee('Kako mogu platiti narudžbu?');
    }
}
