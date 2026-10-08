@php
    $flashIcons = ['success' => 'check-circle', 'error' => 'x-circle', 'warning' => 'alert', 'info' => 'info'];
@endphp
@foreach ($flashIcons as $key => $icon)
    @if (session()->has($key))
        <div class="alert alert-{{ $key }}" role="{{ $key === 'error' ? 'alert' : 'status' }}">
            <x-icon :name="$icon" />
            <div class="body">{{ session($key) }}</div>
            <button type="button" class="icon-btn close" data-dismiss aria-label="Dismiss"><x-icon name="x" class="icon-sm" /></button>
        </div>
    @endif
@endforeach

@if ($errors->any())
    <div class="alert alert-error" role="alert">
        <x-icon name="x-circle" />
        <div class="body">
            @if ($errors->count() === 1)
                {{ $errors->first() }}
            @else
                <strong>Please fix the following:</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        <button type="button" class="icon-btn close" data-dismiss aria-label="Dismiss"><x-icon name="x" class="icon-sm" /></button>
    </div>
@endif
