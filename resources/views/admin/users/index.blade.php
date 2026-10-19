@extends('layouts.app')

@section('title', 'Staff Accounts & Roles')
@section('subtitle', 'Staff User Management & Role-Based Access Control (RBAC)')

@section('top_action')
    <a href="{{ route('dashboard') }}" class="bg-[#333333] hover:bg-[#222222] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm border border-[#666666]">
        &larr; Return to Dashboard
    </a>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4 max-w-6xl mx-auto">

    <!-- Header & RBAC Notice -->
    <div class="bg-white p-3 border-l-4 {{ $isOwner ? 'border-amber-600' : 'border-slate-400' }} shadow-sm flex flex-wrap items-center justify-between gap-3 text-xs">
        <div>
            <h1 class="font-bold text-slate-900 text-sm uppercase tracking-wide">Staff Accounts & Security Directory</h1>
            <p class="text-slate-500">
                Discrete RBAC roles: Owner (Full Access & Credentials), Admin (Rates & Finance), Cashier (Front Desk & POS), Kitchen (KDS TV), and Customer Display (Lobby Kiosk).
            </p>
        </div>

        @if($isOwner)
            <button type="button" onclick="openNewUserModal()" class="px-4 py-1.5 bg-[#421A2B] hover:bg-[#341421] text-white font-bold text-xs rounded shadow flex items-center space-x-1">
                <span>+</span>
                <span>Provision New Staff User</span>
            </button>
        @else
            <span class="px-3 py-1 bg-slate-100 text-slate-600 font-bold text-[11px] rounded border border-slate-300">
                🔒 Read-Only (Owner Privilege Required for Modifications)
            </span>
        @endif
    </div>

    <!-- Staff Directory Table -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>REGISTERED SYSTEM OPERATORS ({{ $users->where('is_active', true)->count() }} ACTIVE / {{ $users->count() }} TOTAL)</span>
            <span class="text-[11px] font-normal text-slate-500">Authentication Scheme: bcrypt</span>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>STAFF NAME</th>
                        <th>LOGIN EMAIL</th>
                        <th>ASSIGNED ROLE</th>
                        <th>PERMISSIONS LEVEL</th>
                        <th>CREATED DATE</th>
                        @if($isOwner)
                            <th>SECURITY CONTROLS</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        @php
                            $role = $user->roles->first()?->name ?? 'none';
                        @endphp
                        <tr>
                            <td class="font-bold text-slate-900 text-left pl-3">
                                {{ $user->name }}
                                @if(!$user->is_active)
                                    <span class="block text-[10px] text-rose-700 uppercase">Deactivated</span>
                                @endif
                            </td>
                            <td class="font-mono text-slate-700 text-left pl-2">{{ $user->email }}</td>
                            <td>
                                @if($role === 'owner')
                                    <span class="px-2 py-0.5 bg-amber-100 text-amber-900 font-black text-[10px] rounded uppercase border border-amber-300">
                                        👑 OWNER
                                    </span>
                                @elseif($role === 'admin')
                                    <span class="px-2 py-0.5 bg-purple-100 text-purple-900 font-bold text-[10px] rounded uppercase border border-purple-200">
                                        🛡️ ADMIN
                                    </span>
                                @elseif($role === 'cashier')
                                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 font-bold text-[10px] rounded uppercase">
                                        💼 CASHIER
                                    </span>
                                @elseif($role === 'kitchen')
                                    <span class="px-2 py-0.5 bg-sky-100 text-sky-800 font-bold text-[10px] rounded uppercase">
                                        🍳 KITCHEN
                                    </span>
                                @elseif($role === 'customer_display')
                                    <span class="px-2 py-0.5 bg-slate-100 text-slate-700 font-bold text-[10px] rounded uppercase">
                                        📺 KIOSK DISPLAY
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 bg-gray-100 text-gray-600 font-bold text-[10px] rounded uppercase">
                                        {{ strtoupper($role) }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-slate-600 text-[11px]">
                                @if($role === 'owner')
                                    All Privileges + User & Password Mgmt
                                @elseif($role === 'admin')
                                    P&L, Master Rates, Accounting & Drawer
                                @elseif($role === 'cashier')
                                    Front Desk, Rooms, Orders, Shift Recount
                                @elseif($role === 'kitchen')
                                    Kitchen Display System & Order Status
                                @elseif($role === 'customer_display')
                                    Public Lobby Kiosk Display
                                @else
                                    Standard User
                                @endif
                            </td>
                            <td class="font-mono text-slate-500">{{ $user->created_at ? $user->created_at->format('m/d/Y') : '—' }}</td>
                            @if($isOwner)
                                <td>
                                    <div class="flex items-center justify-center space-x-1.5">
                                        <button type="button" 
                                            onclick="openResetModal(this.dataset.userId, this.dataset.userName, this.dataset.userEmail)" data-user-id="{{ $user->id }}" data-user-name="{{ $user->name }}" data-user-email="{{ $user->email }}"
                                            class="px-2 py-0.5 bg-slate-800 hover:bg-black text-white text-[10px] font-bold rounded shadow-sm">
                                            🔑 Reset Pass
                                        </button>
                                        @if($user->id !== auth()->id() && $user->is_active)
                                            <form method="POST" action="{{ route('admin.users.destroy', $user->id) }}" class="inline" onsubmit="return confirm('Deactivate this staff account? Transaction history will be preserved.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="px-2 py-0.5 bg-rose-600 hover:bg-rose-700 text-white text-[10px] font-bold rounded shadow-sm">
                                                    Deactivate
                                                </button>
                                            </form>
                                        @elseif($user->id === auth()->id())
                                            <span class="text-slate-400 text-[10px] italic">You</span>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>

@if($isOwner)
    <!-- Modal: Add New Staff User -->
    <div id="newUserModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-3">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full border border-slate-300 overflow-hidden">
            <div class="bg-[#421A2B] text-white px-4 py-2.5 flex items-center justify-between font-bold text-xs uppercase tracking-wider">
                <span>+ Provision New Staff Account</span>
                <button type="button" onclick="closeNewUserModal()" class="text-white hover:text-slate-200 text-base leading-none">&times;</button>
            </div>

            <form method="POST" action="{{ route('admin.users.store') }}" class="p-4 space-y-3 text-xs">
                @csrf

                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Full Name: <span class="text-rose-600">*</span></label>
                    <input type="text" name="name" required placeholder="e.g. Juan Perez" class="form-control-hms">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Email / Login Username: <span class="text-rose-600">*</span></label>
                    <input type="email" name="email" required placeholder="e.g. juan@sedonapms.com" class="form-control-hms font-mono">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Assigned Role: <span class="text-rose-600">*</span></label>
                    <select name="role" required class="form-control-hms">
                        <option value="cashier">Cashier (Front Desk & POS)</option>
                        <option value="kitchen">Kitchen Staff (KDS Display Only)</option>
                        <option value="admin">Admin (Rates & Accounting)</option>
                        <option value="owner">Owner (Full System Access)</option>
                        <option value="customer_display">Customer Display (Lobby Kiosk)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Initial Password: <span class="text-rose-600">*</span></label>
                    <input type="password" name="password" required autocomplete="new-password" minlength="6" class="form-control-hms font-mono">
                    <span class="text-[10px] text-slate-500">Choose a unique password (minimum 6 characters).</span>
                </div>

                <div class="flex items-center justify-end space-x-2 pt-2 border-t">
                    <button type="button" onclick="closeNewUserModal()" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded text-xs">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-1.5 bg-[#421A2B] hover:bg-[#341421] text-white font-bold rounded text-xs shadow">
                        Create Staff Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Reset User Password -->
    <div id="resetModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-3">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full border border-slate-300 overflow-hidden">
            <div class="bg-slate-900 text-white px-4 py-2.5 flex items-center justify-between font-bold text-xs uppercase tracking-wider">
                <span>🔑 Reset Staff Password</span>
                <button type="button" onclick="closeResetModal()" class="text-white hover:text-slate-200 text-base leading-none">&times;</button>
            </div>

            <form id="resetForm" method="POST" action="" class="p-4 space-y-3 text-xs">
                @csrf
                @method('POST')

                <div class="p-2.5 bg-slate-50 border rounded text-slate-700">
                    Resetting password for: <strong id="resetUserName" class="text-slate-900"></strong><br>
                    Email: <span id="resetUserEmail" class="font-mono text-slate-600"></span>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">New Password: <span class="text-rose-600">*</span></label>
                    <input type="password" name="password" required autocomplete="new-password" minlength="6" class="form-control-hms font-mono font-bold">
                    <span class="text-[10px] text-slate-500">Enter new password for this user (minimum 6 characters).</span>
                </div>

                <div class="flex items-center justify-end space-x-2 pt-2 border-t">
                    <button type="button" onclick="closeResetModal()" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded text-xs">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-1.5 bg-slate-900 hover:bg-black text-white font-bold rounded text-xs shadow">
                        Save New Password
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif

@push('scripts')
<script>
    function openNewUserModal() {
        document.getElementById('newUserModal')?.classList.remove('hidden');
    }

    function closeNewUserModal() {
        document.getElementById('newUserModal')?.classList.add('hidden');
    }

    function openResetModal(userId, userName, userEmail) {
        document.getElementById('resetUserName').textContent = userName;
        document.getElementById('resetUserEmail').textContent = userEmail;
        document.getElementById('resetForm').action = `/admin/users/${userId}/reset-password`;
        document.getElementById('resetModal')?.classList.remove('hidden');
    }

    function closeResetModal() {
        document.getElementById('resetModal')?.classList.add('hidden');
    }
</script>
@endpush
@endsection
