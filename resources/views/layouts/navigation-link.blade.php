<a href="{{ route($link['route']) }}"
   class="sidebar-link {{ request()->routeIs(...$link['patterns']) ? 'active' : '' }}"
   @if(request()->routeIs(...$link['patterns'])) aria-current="page" @endif
   @if($link['new_tab'] ?? false) target="_blank" rel="noopener" @endif>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="{{ $link['icon'] }}" />
    </svg>
    <span>{{ $link['label'] }}</span>
    @if($link['new_tab'] ?? false)<span class="ml-auto text-[10px]" aria-hidden="true">↗</span>@endif
</a>
