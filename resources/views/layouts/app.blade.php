<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#572039">
    <title>@yield('title', 'Dashboard') | {{ config('sedona.hotel_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="app-shell">
    @php
        $operator = auth()->user();
        $isExecutive = $operator?->hasAnyRole(['owner', 'admin', 'manager']) ?? false;
        $isAdministrator = $operator?->hasAnyRole(['owner', 'admin']) ?? false;
        $navigation = [
            ['label' => 'Front Desk', 'route' => 'dashboard', 'patterns' => ['dashboard', 'rooms.*', 'checkout.*', 'folios.*', 'checkin.*'], 'icon' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z'],
            ['label' => 'Reservations', 'route' => 'bookings.index', 'patterns' => ['bookings.*'], 'icon' => 'M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2 M8 14h2 M14 14h2 M8 18h2'],
            ['label' => 'POS', 'route' => 'pos.index', 'patterns' => ['pos.*'], 'icon' => 'M3 3h2l3 12h11l2-9H6 M8 15l-1 3h13 M9 21h.01 M18 21h.01'],
            ['label' => 'Shifts & Drawer', 'route' => 'shifts.index', 'patterns' => ['shifts.*'], 'icon' => 'M3 7h18v13H3z M7 7V4h10v3 M3 12h18 M10 12v3h4v-3'],
            ['label' => 'Stock', 'route' => 'inventory.index', 'patterns' => ['inventory.*'], 'icon' => 'M12 3 3 8l9 5 9-5-9-5z M3 8v9l9 5 9-5V8 M12 13v9 M7 5.8l10 5.5'],
        ];
        $kitchenNavigation = [
            ['label' => 'Kitchen KDS', 'route' => 'kitchen.view', 'patterns' => ['kitchen.view'], 'icon' => 'M4 3v7a3 3 0 0 0 6 0V3 M7 3v18 M17 3c-3 3-3 8 0 9v9 M17 3v9h3V3'],
            ['label' => 'Kitchen TV', 'route' => 'kitchen.tv', 'patterns' => ['kitchen.tv'], 'new_tab' => true, 'icon' => 'M3 4h18v13H3z M8 21h8 M12 17v4'],
        ];
        if ($operator?->can('manage_folios')) {
            array_splice($navigation, 1, 0, [['label' => 'Daily SCTI Register', 'route' => 'receipts.index', 'patterns' => ['receipts.*', 'checkins.*'], 'icon' => 'M5 3h14v18H5z M8 7h8 M8 11h8 M8 15h5']]);
        }
        if ($operator?->canAny(['manage_shifts', 'manage_folios', 'post_pos_sales'])) {
            $navigation[] = ['label' => 'Approvals & Tasks', 'route' => 'approvals.index', 'patterns' => ['approvals.*'], 'icon' => 'M5 3h14v18H5z M8 8l2 2 4-4'];
        }
        if ($operator?->canAny(['record_payments', 'post_pos_sales'])) {
            $navigation[] = ['label' => 'Transactions & Audit', 'route' => 'transactions.index', 'patterns' => ['transactions.*'], 'icon' => 'M5 3h14v18H5z M8 7h8 M8 11h8 M8 15h5'];
        }
        $workspaceAccess = [
            'dashboard' => $operator?->canAny(['view_dashboard', 'view_kds', 'view_kiosk']),
            'bookings.index' => $operator?->can('manage_folios'),
            'checkins.index' => $operator?->can('manage_folios'),
            'receipts.index' => $operator?->can('manage_folios'),
            'pos.index' => $operator?->can('post_pos_sales'),
            'shifts.index' => $operator?->can('manage_shifts'),
            'inventory.index' => $operator?->hasAnyRole(['cashier', 'front_desk', 'admin', 'owner', 'manager']),
            'approvals.index' => $operator?->canAny(['manage_shifts', 'manage_folios', 'post_pos_sales']),
            'transactions.index' => $operator?->canAny(['record_payments', 'post_pos_sales']),
        ];
        $navigation = array_values(array_filter($navigation, fn (array $link): bool => $workspaceAccess[$link['route']] ?? false));
        $lobbyNavigation = [
            ['label' => 'Lobby Kiosk Display', 'route' => 'kiosk.view', 'patterns' => ['kiosk.*'], 'new_tab' => true, 'icon' => 'M3 4h18v13H3z M8 21h8 M12 17v4'],
        ];
        if ($operator?->hasRole('kitchen')) {
            $navigation = $kitchenNavigation;
        } elseif ($operator?->hasRole('customer_display')) {
            $navigation = $lobbyNavigation;
        } elseif ($operator?->hasAnyRole(['cashier', 'front_desk'])) {
            $navigation = array_merge($navigation, $lobbyNavigation);
        }
        $financeNavigation = [
            ['label' => 'Report Exports', 'route' => 'admin.accounting.reports', 'patterns' => ['admin.accounting.reports'], 'icon' => 'M5 3h14v18H5z M8 7h8 M8 11h8 M8 15h5'],
            ['label' => 'Ledger', 'route' => 'admin.accounting.ledger', 'patterns' => ['admin.accounting.ledger'], 'icon' => 'M5 3h14v18H5z M8 7h8 M8 11h8 M8 15h5'],
            ['label' => 'Daily Finance', 'route' => 'admin.finance.index', 'patterns' => ['admin.finance.*'], 'icon' => 'M3 5h18v15H3z M7 3v4 M17 3v4 M7 12h10'],
            ['label' => 'Executive P&L', 'route' => 'admin.accounting.pnl', 'patterns' => ['admin.accounting.pnl*'], 'icon' => 'M4 20V4 M4 20h16 M8 16v-5 M13 16V7 M18 16V3'],
            ['label' => 'Expenses & Petty Cash', 'route' => 'admin.accounting.expenses', 'patterns' => ['admin.accounting.expenses*'], 'icon' => 'M4 6h16v14H4z M4 6V4h14 M15 11h5v5h-5z M16 13h.01'],
            ['label' => 'Shift Reconciliations', 'route' => 'admin.accounting.shifts', 'patterns' => ['admin.accounting.shifts'], 'icon' => 'M4 4h16v16H4z M8 8h8 M8 12h3 M14 12h2 M8 16h8'],
            ['label' => 'Loss Slips', 'route' => 'admin.accounting.losses', 'patterns' => ['admin.accounting.losses'], 'icon' => 'M5 3h14v18H5z M9 8l6 6 M15 8l-6 6'],
        ];
        $settingsNavigation = [
            ['label' => 'Discounts', 'route' => 'admin.discounts.index', 'patterns' => ['admin.discounts.*'], 'icon' => 'M4 4h7l9 9-7 7-9-9V4z M8 8h.01'],
            ['label' => 'Login History', 'route' => 'admin.login-history.index', 'patterns' => ['admin.login-history.*'], 'icon' => 'M12 8v4l3 2 M21 12a9 9 0 1 1-9-9 M16 3h5v5'],
            ['label' => 'Master Pricing Editor', 'route' => 'admin.pricing.index', 'patterns' => ['admin.pricing.*'], 'icon' => 'M4 4h7l9 9-7 7-9-9V4z M8 8h.01'],
            ['label' => 'Staff Accounts & RBAC', 'route' => 'admin.users.index', 'patterns' => ['admin.users.*'], 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M19 8a3 3 0 0 1 0 6 M22 21v-2a4 4 0 0 0-3-4'],
        ];
        if ($operator?->hasAnyRole(['admin', 'owner'])) {
            array_unshift($settingsNavigation, ['label' => 'Check-in Time Corrections', 'route' => 'admin.check-in-times.index', 'patterns' => ['admin.check-in-times.*'], 'icon' => 'M12 8v4l3 2 M21 12a9 9 0 1 1-9-9 M16 3h5v5']);
        }
    @endphp
    <a href="#main-content" class="skip-link">Skip to content</a>
    <button type="button" id="sidebarOverlay" class="sidebar-overlay hidden" onclick="closePrimaryNav()" aria-label="Close navigation" tabindex="-1"></button>
    <aside class="app-sidebar" id="primaryNavMobile">
        <button type="button" class="sidebar-close" onclick="closePrimaryNav()" aria-label="Close navigation">×</button>
        <a href="{{ route('dashboard') }}" class="app-brand" aria-label="{{ config('sedona.hotel_name') }} home">
            <img class="brand-mark" src="{{ asset('billing-sedona-logo.png') }}" alt="Sedona Rose logo" width="42" height="54">
            <span><span class="brand-name">SEDONA ROSE</span><span class="brand-caption">TRAVELLERS INN</span></span>
        </a>
        <div class="sidebar-content">
            <div>
                <div class="nav-section-label">Workspace</div>
                <nav class="sidebar-nav" id="primaryNav" aria-label="Primary">
                    @foreach($navigation as $link)
                        @include('layouts.navigation-link', ['link' => $link])
                    @endforeach
                    @if($isExecutive)
                        @include('layouts.navigation-link', ['link' => ['label' => 'Reports & Audits', 'route' => 'reports.index', 'patterns' => ['reports.*'], 'icon' => 'M4 20V4 M4 20h16 M8 16v-5 M13 16V7 M18 16V3']])
                    @endif
                </nav>
            </div>
            @if($isAdministrator)
                <div>
                    <div class="nav-section-label">Finance</div>
                    <nav class="sidebar-nav" aria-label="Financial reports">
                        @foreach($financeNavigation as $link)
                            @include('layouts.navigation-link', ['link' => $link])
                        @endforeach
                    </nav>
                </div>
                <div>
                    <div class="nav-section-label">System Settings</div>
                    <nav class="sidebar-nav" aria-label="Administration">
                        @foreach($settingsNavigation as $link)
                            @include('layouts.navigation-link', ['link' => $link])
                        @endforeach
                        @include('layouts.navigation-link', ['link' => $kitchenNavigation[0]])
                        @include('layouts.navigation-link', ['link' => $lobbyNavigation[0]])
                    </nav>
                </div>
            @endif
        </div>
        <div class="sidebar-footer">
            <strong>{{ config('sedona.hotel_name') }}</strong>
            A clear view of every stay.
        </div>
    </aside>

    <div class="app-workspace">
        <header class="app-topbar">
            <div class="topbar-context">
                <button type="button" id="primaryNavToggle" onclick="togglePrimaryNav()" class="nav-toggle" aria-expanded="false" aria-controls="primaryNavMobile" aria-label="Toggle navigation">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16 M4 12h16 M4 18h16"/></svg>
                </button>
                <span>Workspace</span><span aria-hidden="true">/</span><strong>@yield('title', 'Dashboard')</strong>
            </div>
            <div class="topbar-actions">
                <span class="clock-display" id="live-hms-time"></span>
                <button type="button" id="soundToggleBtn" onclick="if(window.sedonaAudio) window.sedonaAudio.toggleMute()" class="sound-control" title="Toggle Sound Alarms & Chimes">
                    <span id="soundToggleIcon" aria-hidden="true">🔊</span><span id="soundToggleText">Sound ON</span>
                </button>
                @if($isExecutive)
                    <div class="relative" data-dropdown>
                        <button type="button" onclick="toggleNavDropdown(event)" aria-expanded="false" aria-haspopup="true" class="sound-control" aria-label="Notifications">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4"/></svg>
                            @if(($notifCount ?? 0) > 0)<span>{{ $notifCount }}</span>@endif
                        </button>
                        <div class="nav-menu hidden absolute right-0 top-full mt-2 w-72" role="menu">
                            <div class="px-3 py-2 text-xs font-bold text-brand">Attention needed</div>
                            @isset($notifItems)
                                @forelse($notifItems as $notification)
                                    <a href="{{ $notification['url'] }}" role="menuitem">{{ $notification['count'] }} {{ $notification['label'] }}</a>
                                @empty
                                    <div class="px-3 py-3 text-xs text-slate-500">All clear — nothing pending.</div>
                                @endforelse
                            @else
                                <a href="{{ route('dashboard') }}" role="menuitem">Open Frontdesk board</a>
                            @endisset
                        </div>
                    </div>
                @endif
                <div class="relative" data-dropdown>
                    <button type="button" class="account-trigger" onclick="toggleNavDropdown(event)" aria-expanded="false" aria-haspopup="true" aria-label="Account menu">
                        <span class="account-avatar">{{ mb_strtoupper(mb_substr($operator->name ?? 'S', 0, 1)) }}</span>
                        <span><span class="account-name">{{ $operator->name ?? 'Staff' }}</span><span class="account-role">{{ str_replace('_', ' ', $operator?->getRoleNames()->first() ?? 'staff') }}</span></span>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="nav-menu hidden absolute right-0 top-full mt-2 w-56" role="menu">
                        <div class="px-3 py-2 border-b border-slate-100">
                            <div class="text-xs font-bold text-slate-700">{{ $operator->name ?? 'Staff' }}</div>
                            <div class="text-[11px] text-slate-500 mt-1 break-all">{{ $operator->email ?? '' }}</div>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" role="menuitem">Logout</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        <div class="app-page-header">
            <div>
                <h1>@yield('title', 'Dashboard')</h1>
                <p class="page-description">@yield('subtitle', 'Your property, at a glance.')</p>
            </div>
            <div class="page-actions">@yield('top_action')</div>
        </div>
            <!-- Flash Messages -->
    <div class="app-feedback">
        @if($errors->any() && !request()->routeIs('inventory.index', 'folios.orders.index', 'approvals.index', 'admin.discounts.index', 'admin.check-in-times.index', 'admin.pricing.index', 'checkout.process'))
            <div role="alert" class="p-3 mb-3 bg-rose-50 border border-rose-500 text-rose-900 text-sm rounded">
                <p class="font-bold">The change could not be saved.</p>
                <ul class="list-disc pl-5 mt-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if(session('success'))
            <div class="p-2 mb-2 bg-emerald-50 border border-emerald-500 text-emerald-900 text-xs rounded font-medium flex items-center justify-between">
                <div>
                    <div><strong>SUCCESS:</strong> {{ session('success') }}</div>
                    @if(session('settled_folio_id'))
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <a href="{{ route('folios.receipt', session('settled_folio_id')) }}" target="_blank" class="px-2 py-0.5 bg-brand hover:bg-[#341421] text-white font-bold text-[10px] rounded shadow-sm inline-flex items-center gap-1">
                                🖨️ Official Receipt (80mm)
                            </a>
                            <a href="{{ route('folios.gate_pass', session('settled_folio_id')) }}" target="_blank" class="px-2 py-0.5 bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-[10px] rounded shadow-sm inline-flex items-center gap-1">
                                🚪 Exit Gate Pass (80mm)
                            </a>
                            <a href="{{ route('folios.deposit_refund', session('settled_folio_id')) }}" target="_blank" class="px-2 py-0.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-[10px] rounded shadow-sm inline-flex items-center gap-1">
                                💵 Deposit Refund Slip
                            </a>
                            <a href="{{ route('folios.billing', session('settled_folio_id')) }}" target="_blank" class="px-2 py-0.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-[10px] rounded shadow-sm inline-flex items-center gap-1">
                                📄 Statement of Account
                            </a>
                        </div>
                    @endif
                </div>
                <button onclick="this.closest('div.bg-emerald-50').remove()" class="text-emerald-700 font-bold ml-3 text-base">&times;</button>
            </div>
        @endif
        @if(session('error'))
            <div class="p-2 mb-2 bg-rose-50 border border-rose-500 text-rose-900 text-xs rounded font-medium flex items-center justify-between">
                <span><strong>ERROR:</strong> {{ session('error') }}</span>
                <button onclick="this.parentElement.remove()" class="text-rose-700 font-bold">&times;</button>
            </div>
        @endif
        @if(session('info'))
            <div class="p-2 mb-2 bg-blue-50 border border-blue-500 text-blue-900 text-xs rounded font-medium flex items-center justify-between">
                <span><strong>NOTICE:</strong> {{ session('info') }}</span>
                <button onclick="this.parentElement.remove()" class="text-blue-700 font-bold">&times;</button>
            </div>
        @endif
    </div>


        <main id="main-content" class="app-content" tabindex="-1">
            @yield('content')
        </main>
    </div>
    <script>
        function togglePrimaryNav() {
            const sidebar = document.getElementById('primaryNavMobile');
            const open = !sidebar.classList.contains('is-open');
            sidebar.classList.toggle('is-open', open);
            document.getElementById('sidebarOverlay').classList.toggle('hidden', !open);
            document.getElementById('primaryNavToggle').setAttribute('aria-expanded', String(open));
            document.body.classList.toggle('nav-open', open);
            document.querySelector('.app-workspace').inert = open;
            if (open) sidebar.querySelector('a').focus();
        }
        function closePrimaryNav(restoreFocus = true) {
            document.getElementById('primaryNavMobile').classList.remove('is-open');
            document.getElementById('sidebarOverlay').classList.add('hidden');
            document.getElementById('primaryNavToggle').setAttribute('aria-expanded', 'false');
            document.body.classList.remove('nav-open');
            document.querySelector('.app-workspace').inert = false;
            if (restoreFocus) document.getElementById('primaryNavToggle').focus();
        }
        window.matchMedia('(min-width: 1024px)').addEventListener('change', event => {
            if (event.matches) closePrimaryNav(false);
        });
        function toggleNavDropdown(event) {
            event.stopPropagation();
            const wrapper = event.currentTarget.closest('[data-dropdown]');
            const menu = wrapper.querySelector('.nav-menu');
            const open = menu.classList.contains('hidden');
            closeAllNavDropdowns(wrapper);
            menu.classList.toggle('hidden', !open);
            wrapper.querySelector('[aria-expanded]').setAttribute('aria-expanded', String(open));
            if (open) menu.querySelector('a, button')?.focus();
        }
        function closeAllNavDropdowns(except) {
            document.querySelectorAll('[data-dropdown] .nav-menu').forEach(menu => {
                const wrapper = menu.closest('[data-dropdown]');
                if (wrapper !== except) {
                    menu.classList.add('hidden');
                    wrapper.querySelector('[aria-expanded]').setAttribute('aria-expanded', 'false');
                }
            });
        }
        document.addEventListener('click', event => {
            if (!event.target.closest('[data-dropdown]')) closeAllNavDropdowns(null);
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                const trigger = document.querySelector('[data-dropdown] [aria-expanded="true"]');
                closeAllNavDropdowns(null);
                trigger?.focus();
                if (document.getElementById('primaryNavMobile').classList.contains('is-open')) closePrimaryNav();
            }
            if (event.key === 'Tab' && document.getElementById('primaryNavMobile').classList.contains('is-open') && window.matchMedia('(max-width: 1023px)').matches) {
                const controls = [...document.getElementById('primaryNavMobile').querySelectorAll('a, button')];
                const first = controls[0];
                const last = controls.at(-1);
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        function updateHmsClock() {
            const now = new Date();
            const date = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            const time = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
            document.getElementById('live-hms-time').textContent = `${date} · ${time}`;
        }
        setInterval(updateHmsClock, 1000);
        updateHmsClock();
        document.addEventListener('DOMContentLoaded', () => {
            window.sedonaAudio?.updateAudioButtonState();
        });
    </script>
    @stack('scripts')
</body>
</html>
