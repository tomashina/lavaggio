@props([
    'rows' => 8,
    'mediaTools' => false,
])

<div class="admin-quill-field">
    <textarea {{ $attributes->merge([
        'rows' => $rows,
        'data-quill-editor' => '',
        'data-document-library-url' => $mediaTools && \Illuminate\Support\Facades\Route::has('admin.content.documents.index') ? route('admin.content.documents.index') : null,
        'class' => 'hidden',
    ]) }}></textarea>
    <div wire:ignore class="admin-quill" data-quill-mount>
        <div class="admin-quill-editor"></div>
    </div>
</div>
