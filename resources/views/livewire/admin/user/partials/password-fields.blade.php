<div class="admin-panel admin-form-panel p-6">
    <p class="admin-section-title">{{ $isCreate ? __('Lozinka') : __('Password Reset (Optional)') }}</p>
    <p class="mt-1 text-sm text-slate-600">{{ $isCreate ? __('Lozinka mora imati najmanje 8 znakova. Ponovite je u polju za potvrdu.') : __('Leave blank to keep current password.') }}</p>

    <div class="mt-4 grid gap-3 md:grid-cols-2">
        <div>
            <label for="user-password" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('New Password') }}</label>
            <input id="user-password" type="password" wire:model="form.password" minlength="8" @required($isCreate) class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" autocomplete="new-password" />
            @error('form.password')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="user-password-confirmation" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Confirm Password') }}</label>
            <input id="user-password-confirmation" type="password" wire:model="form.password_confirmation" @required($isCreate) class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" autocomplete="new-password" />
            @error('form.password_confirmation')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
    </div>
</div>
