<fieldset>
    <legend class="mb-4 text-base font-bold text-slate-900">Vaši podaci i narudžba</legend>
    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label for="withdrawal-full-name" class="{{ $labelClass }}">{{ __('return_request.form.full_name') }} <x-front.required-mark /></label>
            <input id="withdrawal-full-name" type="text" name="full_name" value="{{ old('full_name', $prefill['full_name'] ?? '') }}" autocomplete="name" class="{{ $fieldClass }}" required maxlength="191" @error('full_name') aria-invalid="true" @enderror>
            @error('full_name') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="withdrawal-email" class="{{ $labelClass }}">{{ __('return_request.form.email') }} <x-front.required-mark /></label>
            <input id="withdrawal-email" type="email" name="email" value="{{ old('email', $prefill['email'] ?? '') }}" autocomplete="email" class="{{ $fieldClass }}" required maxlength="191" @error('email') aria-invalid="true" @enderror>
            <p class="mt-1 text-xs text-slate-500">{{ __('return_request.form.email_help') }}</p>
            @error('email') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-2">
            <label for="withdrawal-order-number" class="{{ $labelClass }}">{{ __('return_request.form.order_number') }} <x-front.required-mark /></label>
            <input id="withdrawal-order-number" type="text" name="order_number" value="{{ old('order_number') }}" class="{{ $fieldClass }}" required maxlength="80" @error('order_number') aria-invalid="true" @enderror>
            @error('order_number') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
        </div>
    </div>
    <div class="mt-4">
        <label for="withdrawal-items" class="{{ $labelClass }}">Proizvodi koje vraćate (neobavezno)</label>
        <textarea id="withdrawal-items" name="items" rows="3" class="{{ $fieldClass }}" placeholder="Ostavite prazno ako raskidate cijelu narudžbu." maxlength="5000" @error('items') aria-invalid="true" @enderror>{{ old('items') }}</textarea>
        <p class="mt-1 text-xs text-slate-500">Za dio narudžbe navedite proizvode i količine. Razlog ne morate navesti.</p>
        @error('items') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
    </div>
</fieldset>

<details class="mt-6 border-t border-slate-200 pt-4" @if($errors->has('phone') || $errors->has('received_date')) open @endif>
    <summary class="cursor-pointer text-sm font-semibold text-slate-700">Dodatni podaci (neobavezno)</summary>
    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <div>
            <label for="withdrawal-phone" class="{{ $labelClass }}">{{ __('return_request.form.phone') }}</label>
            <input id="withdrawal-phone" type="tel" name="phone" value="{{ old('phone', $prefill['phone'] ?? '') }}" autocomplete="tel" class="{{ $fieldClass }}" maxlength="80" @error('phone') aria-invalid="true" @enderror>
            @error('phone') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="withdrawal-received-date" class="{{ $labelClass }}">{{ __('return_request.form.received_date') }}</label>
            <input id="withdrawal-received-date" type="date" name="received_date" value="{{ old('received_date') }}" max="{{ now()->toDateString() }}" class="{{ $fieldClass }}" @error('received_date') aria-invalid="true" @enderror>
            @error('received_date') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
        </div>
    </div>
</details>
