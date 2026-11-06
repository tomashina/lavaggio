<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContentDocumentController extends Controller
{
    private const DIRECTORY = 'content-documents';

    public function index(Request $request): JsonResponse
    {
        $this->authorizeEditor($request);
        $input = $request->validate([
            'folder' => ['nullable', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $disk = Storage::disk('public');
        $folder = $input['folder'] ?? '';
        $search = mb_strtolower(trim($input['q'] ?? ''));
        $documents = collect($disk->allFiles(self::DIRECTORY.($folder !== '' ? '/'.$folder : '')))
            ->filter(fn (string $path): bool => strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf')
            ->filter(fn (string $path): bool => $search === '' || str_contains(mb_strtolower(Str::after(basename($path), '--')), $search))
            ->map(fn (string $path): array => $this->document($path))
            ->sortByDesc('modified_at')
            ->values();
        $page = (int) ($input['page'] ?? 1);

        return response()->json([
            'documents' => $documents->forPage($page, 20)->values(),
            'folders' => collect($disk->directories(self::DIRECTORY))->map(fn (string $path): string => basename($path))->sort()->values(),
            'page' => $page,
            'last_page' => max(1, (int) ceil($documents->count() / 20)),
            'total' => $documents->count(),
            'max_upload_kb' => $this->uploadLimitKilobytes(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeEditor($request);
        $input = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'extensions:pdf', 'max:'.$this->uploadLimitKilobytes()],
            'folder' => ['nullable', 'string', 'max:80'],
        ]);
        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'rb');
        $header = $handle ? fread($handle, 5) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($header !== '%PDF-') {
            throw ValidationException::withMessages(['file' => 'Datoteka mora biti valjani PDF dokument.']);
        }

        $folder = trim(Str::limit(Str::slug(trim($input['folder'] ?? '')), 80, ''), '-') ?: 'opcenito';
        $name = Str::limit(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 120, '') ?: 'dokument';
        $path = $file->storeAs(self::DIRECTORY.'/'.$folder, Str::uuid().'--'.$name.'.pdf', 'public');
        abort_unless(is_string($path) && $path !== '', 500, 'Dokument nije moguće spremiti.');

        return response()->json(['document' => $this->document($path)], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    private function document(string $path): array
    {
        $disk = Storage::disk('public');

        return [
            'id' => hash('sha256', $path),
            'name' => Str::after(basename($path), '--'),
            'url' => $disk->url($path),
            'folder' => basename(dirname($path)),
            'size' => $disk->size($path),
            'modified_at' => $disk->lastModified($path),
        ];
    }

    private function authorizeEditor(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->isA('superadmin') || ($user->can('admin.access') && $user->canAny([
            'content.blog.create', 'content.blog.update', 'content.pages.create', 'content.pages.update',
        ]))), 403);
    }

    private function uploadLimitKilobytes(): int
    {
        $limits = [20 * 1024 * 1024];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ini_parse_quantity((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $setting === 'post_max_size' ? max(1024, $bytes - 1024 * 1024) : $bytes;
            }
        }

        return max(1, (int) floor(min($limits) / 1024));
    }
}
