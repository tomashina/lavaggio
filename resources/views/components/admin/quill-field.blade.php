@props([
    'rows' => 8,
    'mediaTools' => false,
])

<div class="admin-quill-field">
    <textarea {{ $attributes->merge([
        'rows' => $rows,
        'data-quill-editor' => '',
        'data-quill-media-tools' => $mediaTools ? '' : null,
        'data-document-library-url' => $mediaTools && \Illuminate\Support\Facades\Route::has('admin.content.documents.index') ? route('admin.content.documents.index') : null,
        'data-image-upload-url' => $mediaTools && \Illuminate\Support\Facades\Route::has('admin.content.images.store') ? route('admin.content.images.store', absolute: false) : null,
        'class' => 'hidden',
    ]) }}></textarea>
    <div wire:ignore class="admin-quill" data-quill-mount>
        <div class="admin-quill-editor"></div>
    </div>
</div>
