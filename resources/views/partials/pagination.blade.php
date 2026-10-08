@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="btn btn-secondary btn-sm disabled" aria-disabled="true"><x-icon name="chevron-left" /> Previous</span>
        @else
            <a class="btn btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="chevron-left" /> Previous</a>
        @endif

        <span class="muted small">Page {{ $paginator->currentPage() }}</span>

        @if ($paginator->hasMorePages())
            <a class="btn btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next <x-icon name="chevron-right" /></a>
        @else
            <span class="btn btn-secondary btn-sm disabled" aria-disabled="true">Next <x-icon name="chevron-right" /></span>
        @endif
    </nav>
@endif
