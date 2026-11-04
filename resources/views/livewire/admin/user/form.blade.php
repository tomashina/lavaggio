<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Admin / Users') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $isCreate ? __('Novi korisnik') : __('Edit User') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ $isCreate ? __('Unesite ime, e-mail, ulogu i lozinku za novi korisnički račun.') : __('Update identity, segmentation, profile and addresses in one place.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if (! $isCreate)<span class="admin-chip">{{ __('User ID') }}: {{ $userId }}</span>@endif
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to List') }}</button>
            </div>
        </div>
    </div>

    @if (! $isCreate)
    <nav class="flex flex-wrap gap-2 border-b border-slate-200 pb-3" aria-label="{{ __('Kartice kupca') }}">
        <button type="button" wire:click="switchTab('profile')" @class(['rounded-xl px-4 py-2 text-sm font-semibold', 'bg-slate-900 text-white' => $tab === 'profile', 'bg-white text-slate-600 hover:bg-slate-100' => $tab !== 'profile']) aria-current="{{ $tab === 'profile' ? 'page' : 'false' }}">{{ __('Podaci kupca') }}</button>
        @if ($canViewPricing)
            <button type="button" wire:click="switchTab('pricing')" @class(['rounded-xl px-4 py-2 text-sm font-semibold', 'bg-slate-900 text-white' => $tab === 'pricing', 'bg-white text-slate-600 hover:bg-slate-100' => $tab !== 'pricing']) aria-current="{{ $tab === 'pricing' ? 'page' : 'false' }}">{{ __('B2B cjenik') }}</button>
        @endif
    </nav>
    @endif

    @if ($tab === 'pricing' && $canViewPricing)
        <livewire:admin.user.customer-price-manager :user-id="$userId" :key="'customer-pricing-'.$userId" />
    @else
    @if (! $isCreate)
        <livewire:admin.user.b2b-user-profile-editor :user-id="$userId" :key="'b2b-profile-'.$userId" />
    @endif

    <form wire:submit="save" class="space-y-6">
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <p>{{ __('Provjerite označena polja. Korisnik još nije spremljen.') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Core Data') }}</p>

            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 4;">
                    <label for="user-name" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.users.name') }}</label>
                    <input id="user-name" type="text" wire:model="form.name" maxlength="255" autocomplete="name" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 4;">
                    <label for="user-email" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Email') }}</label>
                    <input id="user-email" type="email" wire:model="form.email" maxlength="255" autocomplete="email" required @readonly(! $canManageSecurity) class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span {{ $isCreate ? 4 : 2 }};">
                    <label for="user-role" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Role') }}</label>
                    @if ($canManageSecurity)
                    <select id="user-role" wire:model="form.role" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($roles as $role)
                            <option value="{{ $role->name }}" @selected($form['role'] === $role->name)>{{ $role->title ?: ucfirst($role->name) }}</option>
                        @endforeach
                    </select>
                    @else
                        <p class="py-2 text-sm text-slate-700">{{ __('Customer') }}</p>
                    @endif
                    @error('form.role') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                @if (! $isCreate)
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Segments') }}</label>
                    <select wire:model="form.customer_groups" multiple data-tom-select data-tom-placeholder="{{ __('Select groups...') }}" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($customerGroups as $group)
                            <option value="{{ $group->id }}" @selected(in_array((string) $group->id, array_map('strval', (array) ($form['customer_groups'] ?? [])), true))>{{ $group->name }}{{ $group->isBusinessSegment() ? ' — '.__($group->classificationOptions()[$group->classificationType()]) : '' }}</option>
                        @endforeach
                    </select>
                    @error('form.customer_groups.*') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">{{ __('Djelatnost i divizija su dodatni segmenti; cjenovnu grupu odaberite u B2B postavkama.') }}</p>
                </div>
                @endif
            </div>

            @if ($canManageSecurity)
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    wire:click="$toggle('form.email_verified')"
                    class="admin-switch"
                    data-state="{{ $form['email_verified'] ? 'on' : 'off' }}"
                    role="switch"
                    aria-checked="{{ $form['email_verified'] ? 'true' : 'false' }}"
                    aria-label="{{ __('Toggle email verified state') }}"
                >
                    <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                    <span class="admin-switch-label">{{ $form['email_verified'] ? __('Email Verified') : __('Email Unverified') }}</span>
                </button>
            </div>
            @endif
        </div>

        @if ($isCreate)
            @include('livewire.admin.user.partials.password-fields')
            <details class="admin-panel p-6" @if ($errors->has('form.profile.*') || $errors->has('form.billing_address.*') || $errors->has('form.shipping_address.*') || $errors->has('form.customer_groups.*')) open @endif>
                <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('Dodatni podaci (neobavezno)') }}</summary>
                <div class="mt-5 space-y-6">
                    <div>
                        <label for="new-user-groups" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Grupa kupaca') }}</label>
                        <select id="new-user-groups" wire:model="form.customer_groups" multiple data-tom-select data-tom-placeholder="{{ __('Select groups...') }}" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($customerGroups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                        </select>
                        @error('form.customer_groups.*')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
        @endif

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Profile') }}</p>

            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('First Name') }}</label>
                    <input type="text" wire:model="form.profile.first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Last Name') }}</label>
                    <input type="text" wire:model="form.profile.last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Phone') }}</label>
                    <input type="text" wire:model="form.profile.phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Birthday') }}</label>
                    <input type="date" wire:model="form.profile.birthday" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Gender') }}</label>
                    <select wire:model="form.profile.gender" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">-</option>
                        <option value="male">{{ __('Male') }}</option>
                        <option value="female">{{ __('Female') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Affiliate Name') }}</label>
                    <input type="text" wire:model="form.profile.affiliate_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Company') }}</label>
                    <input type="text" wire:model="form.profile.company" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('OIB') }}</label>
                    <input type="text" wire:model="form.profile.oib" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <div class="mt-6 flex items-center">
                        <button
                            type="button"
                            wire:click="$toggle('form.profile.newsletter_opt_in')"
                            class="admin-switch"
                            data-state="{{ $form['profile']['newsletter_opt_in'] ? 'on' : 'off' }}"
                            role="switch"
                            aria-checked="{{ $form['profile']['newsletter_opt_in'] ? 'true' : 'false' }}"
                            aria-label="{{ __('Toggle newsletter opt in') }}"
                        >
                            <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                            <span class="admin-switch-label">{{ $form['profile']['newsletter_opt_in'] ? __('Newsletter On') : __('Newsletter Off') }}</span>
                        </button>
                    </div>
                </div>
                <div style="grid-column: span 5;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Bio') }}</label>
                    <textarea wire:model="form.profile.bio" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
            </div>
        </div>

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Billing Address') }}</p>
            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('First Name') }}</label>
                    <input type="text" wire:model="form.billing_address.first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Last Name') }}</label>
                    <input type="text" wire:model="form.billing_address.last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Company') }}</label>
                    <input type="text" wire:model="form.billing_address.company" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('OIB') }}</label>
                    <input type="text" wire:model="form.billing_address.oib" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('VAT ID') }}</label>
                    <input type="text" wire:model="form.billing_address.vat_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 6;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Address Line 1') }}</label>
                    <input type="text" wire:model="form.billing_address.address_line_1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 6;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Address Line 2') }}</label>
                    <input type="text" wire:model="form.billing_address.address_line_2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Postal Code') }}</label>
                    <input type="text" wire:model="form.billing_address.postal_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('City') }}</label>
                    <input type="text" wire:model="form.billing_address.city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('CC') }}</label>
                    <input type="text" wire:model="form.billing_address.country_code" maxlength="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase" />
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Phone') }}</label>
                    <input type="text" wire:model="form.billing_address.phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>
        </div>

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Shipping Address') }}</p>
            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('First Name') }}</label>
                    <input type="text" wire:model="form.shipping_address.first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Last Name') }}</label>
                    <input type="text" wire:model="form.shipping_address.last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('OIB') }}</label>
                    <input type="text" wire:model="form.shipping_address.oib" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('VAT ID') }}</label>
                    <input type="text" wire:model="form.shipping_address.vat_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 6;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Address Line 1') }}</label>
                    <input type="text" wire:model="form.shipping_address.address_line_1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 6;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Address Line 2') }}</label>
                    <input type="text" wire:model="form.shipping_address.address_line_2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Postal Code') }}</label>
                    <input type="text" wire:model="form.shipping_address.postal_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('City') }}</label>
                    <input type="text" wire:model="form.shipping_address.city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('CC') }}</label>
                    <input type="text" wire:model="form.shipping_address.country_code" maxlength="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase" />
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Phone') }}</label>
                    <input type="text" wire:model="form.shipping_address.phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>
        </div>

        @if ($isCreate)
                </div>
            </details>
        @elseif ($canManageSecurity)
            @include('livewire.admin.user.partials.password-fields')
        @endif

        <div class="admin-form-actions flex items-center gap-2 pt-2">
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">{{ $isCreate ? __('Kreiraj korisnika') : __('Update User') }}</span>
                <span wire:loading wire:target="save">{{ __('Spremam…') }}</span>
            </button>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                {{ __('Cancel') }}
            </button>
        </div>
    </form>
    @endif
</div>
