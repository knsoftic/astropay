@php($steps = \App\Support\TransactionTimeline::for($transaction))
<ol class="steps" aria-label="Progress">
    @foreach ($steps as $step)
        <li class="step {{ $step['state'] }}">
            <div class="step-dot">
                @if ($step['state'] === 'done')
                    <x-icon name="check" />
                @elseif ($step['state'] === 'failed')
                    <x-icon name="x" />
                @elseif ($step['state'] === 'current')
                    <x-icon name="clock" />
                @else
                    <span class="small strong">{{ $loop->iteration }}</span>
                @endif
            </div>
            <div>
                <div class="step-title">{{ $step['title'] }}</div>
                <div class="step-time">{{ $step['time']?->format('d M, H:i') ?? ($step['state'] === 'current' ? 'In progress' : '—') }}</div>
            </div>
        </li>
    @endforeach
</ol>
