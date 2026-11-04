<details class="relative">
    <summary class="flex h-9 w-9 cursor-pointer list-none items-center justify-center rounded-lg border border-slate-300 text-slate-500 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2" aria-label="Dodatne akcije: {{ $actionBlock->name }}" title="Dodatne akcije">
        <span aria-hidden="true" class="text-base leading-none">•••</span>
    </summary>
    <div class="absolute right-0 z-20 mt-2 w-40 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
        <button type="button" wire:click="openPreview({{ $actionBlock->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">{{ __('Preview') }}</button>
        <button type="button" wire:click="delete({{ $actionBlock->id }})" wire:confirm="{{ __('Delete this content block?') }}" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-rose-700 hover:bg-rose-50">{{ __('Delete') }}</button>
    </div>
</details>
