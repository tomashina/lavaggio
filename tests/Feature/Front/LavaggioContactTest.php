<?php

namespace Tests\Feature\Front;

use App\Models\Content\Support\ContactMessage;
use App\Services\Front\StoreNotificationService;
use Database\Seeders\LavaggioStorefrontSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.locale' => 'hr',
            'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera',
        ]);
        app()->setLocale('hr');
        $this->seed(LavaggioStorefrontSeeder::class);
    }

    public function test_contact_matches_source_content_and_uses_local_form_and_privacy_links(): void
    {
        $response = $this->get(route('contact.create'))->assertOk()
            ->assertSee('Barban 70, 52207, Barban')
            ->assertSee('Trebate čisto rublje bez muke? Javite nam se!')
            ->assertSee('front-theme/styles/lavaggio-contact.css')
            ->assertSee('maps/embed?pb=', false)
            ->assertSee(route('pages.show', 'zastita-podataka-i-prigovor'))
            ->assertDontSee(__('contact.subheading'))
            ->assertDontSee(__('contact.help.body'));

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@data-contact-form]')->item(0);
        $this->assertSame(route('contact.store'), $form->getAttribute('action'));
        $this->assertSame(1, $xpath->query('.//input[@name="name"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="email"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//textarea[@name="message"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="accept_terms"]', $form)->length);
        $this->assertSame(0, $xpath->query('.//input[@name="phone" or @name="subject"]', $form)->length);
    }

    public function test_source_form_fields_still_save_through_existing_contact_backend(): void
    {
        $this->mock(StoreNotificationService::class)
            ->shouldReceive('sendContactNotification')
            ->once()
            ->withArgs(fn (ContactMessage $message): bool => $message->name === 'Lokalni test');

        $this->post(route('contact.store'), [
            'name' => 'Lokalni test',
            'email' => 'kontakt@example.test',
            'message' => 'Upit o pranju kućnog rublja.',
            'accept_terms' => '1',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('contact.create'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Lokalni test',
            'email' => 'kontakt@example.test',
            'subject' => __('contact.form.default_subject'),
            'phone' => null,
            'message' => 'Upit o pranju kućnog rublja.',
        ]);
    }

    public function test_other_storefront_keeps_its_existing_contact_fields(): void
    {
        config()->set('storefront.home', 'watchline');
        $this->get(route('contact.create'))->assertOk()
            ->assertSee('name="phone"', false)
            ->assertSee('name="subject"', false)
            ->assertSee(__('contact.subheading'))
            ->assertDontSee('lavaggio-contact-layout');
    }
}
