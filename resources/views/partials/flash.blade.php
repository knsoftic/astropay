@foreach (['success' => 'success', 'error' => 'error', 'warning' => 'warning', 'info' => 'info'] as $key => $class)
    @if (session()->has($key))
        <div class="alert alert-{{ $class }}" role="status">{{ session($key) }}</div>
    @endif
@endforeach

@if ($errors->any())
    <div class="alert alert-error" role="alert">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
