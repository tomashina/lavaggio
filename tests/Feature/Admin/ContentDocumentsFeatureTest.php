<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ContentDocumentsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_content_editors_can_browse_and_upload_documents(): void
    {
        Storage::fake('public');
        $customer = User::factory()->create();
        Bouncer::assign('customer')->to($customer);
        $this->getJson(route('admin.content.documents.index'))->assertUnauthorized();
        $this->actingAs($customer)->getJson(route('admin.content.documents.index'))->assertForbidden();
        $this->actingAs($customer)->postJson(route('admin.content.documents.store'), ['file' => $this->pdf()])->assertForbidden();

        $viewer = $this->editor('content.pages.view');
        $this->actingAs($viewer)->getJson(route('admin.content.documents.index'))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('admin.content.documents.store'), ['file' => $this->pdf()])->assertForbidden();
        // Non-Livewire uploads must still enforce editor permissions for crafted payloads.
        $this->actingAs($viewer)->postJson(route('admin.content.documents.store'), [
            'file' => $this->pdf(), 'components' => [],
        ])->assertForbidden();
        $this->actingAs($viewer)->getJson(route('admin.content.documents.index', ['components' => []]))->assertForbidden();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_pdf_upload_has_a_public_reusable_url_and_normalized_folder(): void
    {
        Storage::fake('public');
        $editor = $this->editor('content.pages.update');
        $response = $this->actingAs($editor)->postJson(route('admin.content.documents.store'), [
            'file' => $this->pdf(), 'folder' => 'Uvjeti kupnje',
        ])->assertCreated()->assertJsonPath('document.folder', 'uvjeti-kupnje')
            ->assertJsonPath('document.name', 'uvjeti-kupnje.pdf');
        $document = $response->json('document');
        $paths = Storage::disk('public')->allFiles('content-documents/uvjeti-kupnje');
        $this->assertCount(1, $paths);
        $this->assertSame(Storage::disk('public')->url($paths[0]), $document['url']);
        $this->assertStringStartsWith('%PDF-', Storage::disk('public')->get($paths[0]));

        $this->actingAs($this->editor('content.blog.create'))->getJson(route('admin.content.documents.index'))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('documents.0.id', $document['id'])
            ->assertJsonPath('folders.0', 'uvjeti-kupnje');
    }

    public function test_non_pdf_files_disguised_extensions_and_oversized_uploads_are_rejected(): void
    {
        Storage::fake('public');
        $this->actingAs($this->editor('content.blog.update'));
        foreach ([
            UploadedFile::fake()->createWithContent('attack.pdf', '<?php echo "not a PDF";'),
            UploadedFile::fake()->createWithContent('attack.php', '%PDF-1.4 fake'),
            UploadedFile::fake()->create('large.pdf', 20481, 'application/pdf'),
        ] as $file) {
            $this->postJson(route('admin.content.documents.store'), ['file' => $file])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_library_filters_and_paginates_without_exposing_other_storage_directories(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        foreach (range(1, 23) as $number) {
            $disk->put('content-documents/opcenito/id-'.$number.'--dokument-'.$number.'.pdf', '%PDF-1.4');
        }
        $disk->put('content-documents/pravila/id--jamstvo.pdf', '%PDF-1.4');
        $disk->put('elsewhere/private.pdf', '%PDF-1.4');
        $disk->put('content-documents/opcenito/readme.txt', 'Not a document');
        $this->actingAs($this->editor('content.pages.create'));
        $this->getJson(route('admin.content.documents.index', ['folder' => 'opcenito']))
            ->assertOk()->assertJsonCount(20, 'documents')->assertJsonPath('total', 23)->assertJsonPath('last_page', 2);
        $this->getJson(route('admin.content.documents.index', ['folder' => 'opcenito', 'page' => 2]))
            ->assertOk()->assertJsonCount(3, 'documents');
        $this->getJson(route('admin.content.documents.index', ['q' => 'JAMSTVO']))
            ->assertOk()->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.name', 'jamstvo.pdf');
        $this->getJson(route('admin.content.documents.index', ['folder' => '../elsewhere']))->assertUnprocessable();
    }

    private function editor(string $ability): User
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to(['admin.access', $ability]);

        return $user;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('Uvjeti kupnje.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }
}
