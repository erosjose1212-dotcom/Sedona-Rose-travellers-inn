@unless(config('sedona.continuous_shift_enabled'))
@can('manage_any_shifts')
    <label class="block text-xs text-slate-700 my-3">
        <span class="block font-bold mb-1">Drawer override reason</span>
        <input type="text" name="shift_override_reason" maxlength="500" value="{{ old('shift_override_reason') }}" class="form-control-hms" placeholder="Why are you operating another cashier's drawer?">
        <span class="block text-slate-500 mt-1">Required when posting or closing a drawer opened by another cashier.</span>
    </label>
@endcan
@endunless
