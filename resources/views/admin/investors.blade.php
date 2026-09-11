@extends('layouts.app')
@section('title', 'Manage Investors — Admin')

@section('content')
<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap');

:root {
    --ink:      #0D1117;
    --ink-2:    #1C2433;
    --ink-3:    #2D3748;
    --blue:     #1847ED;
    --blue-dim: #EEF2FD;
    --green:    #0B7A4E;
    --green-dim:#E6F7F1;
    --red:      #C8192B;
    --red-dim:  #FDE8EA;
    --amber:    #B45309;
    --amber-dim:#FEF3C7;
    --slate:    #64748B;
    --line:     #E8ECF2;
    --surface:  #F5F7FA;
    --white:    #FFFFFF;
    --radius:   14px;
}

*  { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'DM Sans',sans-serif; background:var(--surface); color:var(--ink); }
h1,h2,h3,h4,h5 { font-family:'Instrument Serif',serif; font-weight:400; }

/* ─── Layout ─── */
.pg { padding:2.5rem; }

.pg-head {
    display:flex; justify-content:space-between; align-items:flex-end;
    margin-bottom:2.5rem;
}
.pg-head-left h1 { font-size:2.25rem; color:var(--ink); line-height:1; margin-bottom:.35rem; }
.pg-head-left p  { color:var(--slate); font-size:.9rem; }

.pg-head-right { display:flex; gap:.75rem; }

/* ─── Summary chips ─── */
.summary-row { display:flex; gap:1rem; margin-bottom:2rem; }

.s-chip {
    flex:1; background:var(--white);
    border:1px solid var(--line); border-radius:var(--radius);
    padding:1.25rem 1.5rem;
    display:flex; align-items:center; gap:1rem;
    transition: box-shadow .2s, transform .2s;
}
.s-chip:hover { box-shadow:0 6px 24px rgba(0,0,0,.07); transform:translateY(-2px); }

.s-chip-icon {
    width:44px; height:44px; border-radius:10px;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.s-chip-icon.b { background:var(--blue-dim);  color:var(--blue); }
.s-chip-icon.g { background:var(--green-dim); color:var(--green); }
.s-chip-icon.a { background:var(--amber-dim); color:var(--amber); }
.s-chip-icon.r { background:var(--red-dim);   color:var(--red); }

.s-chip-val { font-family:'Instrument Serif',serif; font-size:1.65rem; color:var(--ink); }
.s-chip-lbl { font-size:.78rem; color:var(--slate); margin-top:2px; font-weight:500; text-transform:uppercase; letter-spacing:.04em; }

/* ─── Toolbar ─── */
.toolbar {
    display:flex; gap:.75rem; align-items:center;
    margin-bottom:1.5rem; flex-wrap:wrap;
}

.search-wrap {
    flex:1; min-width:220px;
    position:relative;
}
.search-wrap svg { position:absolute; left:.9rem; top:50%; transform:translateY(-50%); color:var(--slate); pointer-events:none; }
.search-input {
    width:100%; padding:.65rem .9rem .65rem 2.5rem;
    border:1.5px solid var(--line); border-radius:9px;
    font-size:.875rem; font-family:inherit; color:var(--ink);
    background:var(--white); transition:border-color .2s;
}
.search-input:focus { outline:none; border-color:var(--blue); }

.filter-sel {
    padding:.65rem 1rem;
    border:1.5px solid var(--line); border-radius:9px;
    font-size:.875rem; font-family:inherit; color:var(--ink);
    background:var(--white); cursor:pointer;
}
.filter-sel:focus { outline:none; border-color:var(--blue); }

/* ─── Table card ─── */
.tbl-card {
    background:var(--white);
    border:1px solid var(--line);
    border-radius:var(--radius);
    overflow:hidden;
}

.inv-table { width:100%; border-collapse:collapse; }

.inv-table thead tr { background:#FAFBFD; }
.inv-table th {
    padding:.875rem 1.25rem;
    text-align:left;
    font-size:.75rem; font-weight:600;
    color:var(--slate);
    text-transform:uppercase; letter-spacing:.06em;
    border-bottom:1px solid var(--line);
    font-family:'DM Sans',sans-serif;
}
.inv-table td {
    padding:.9rem 1.25rem;
    font-size:.875rem;
    border-bottom:1px solid #F3F5F8;
    vertical-align:middle;
}
.inv-table tbody tr { transition:background .15s; cursor:pointer; }
.inv-table tbody tr:hover { background:#F8FAFC; }
.inv-table tbody tr:last-child td { border-bottom:none; }

/* user cell */
.u-cell { display:flex; align-items:center; gap:.75rem; }
.u-av {
    width:38px; height:38px; border-radius:50%; flex-shrink:0;
    background:linear-gradient(135deg,var(--blue) 0%,var(--ink-2) 100%);
    display:flex; align-items:center; justify-content:center;
    font-family:'Instrument Serif',serif; font-size:1rem; color:white;
}
.u-name  { font-weight:600; color:var(--ink); font-size:.875rem; }
.u-email { font-size:.75rem; color:var(--slate); margin-top:1px; }

/* status */
.pill {
    display:inline-flex; padding:.25rem .65rem;
    border-radius:6px; font-size:.73rem; font-weight:700;
    font-family:'DM Sans',sans-serif;
}
.pill.active    { background:var(--green-dim); color:var(--green); }
.pill.suspended { background:var(--red-dim);   color:var(--red); }
.pill.pending   { background:var(--amber-dim); color:var(--amber); }

/* balance */
.bal { font-family:'Instrument Serif',serif; font-size:1.05rem; color:var(--ink); }

/* row actions */
.row-actions { display:flex; gap:.375rem; }

.ra-btn {
    width:30px; height:30px; border-radius:7px;
    display:inline-flex; align-items:center; justify-content:center;
    border:1.5px solid var(--line); background:var(--white);
    cursor:pointer; color:var(--slate); transition:all .15s;
}
.ra-btn:hover.edit    { background:var(--blue);  border-color:var(--blue);  color:white; }
.ra-btn:hover.msg     { background:var(--green); border-color:var(--green); color:white; }
.ra-btn:hover.suspend { background:var(--red);   border-color:var(--red);   color:white; }
.ra-btn:hover.activate{ background:var(--green); border-color:var(--green); color:white; }
.ra-btn:hover.bal-btn { background:var(--amber); border-color:var(--amber); color:white; }

/* ─── Slide-over panel ─── */
.overlay {
    position:fixed; inset:0;
    background:rgba(13,17,23,.55);
    backdrop-filter:blur(4px);
    z-index:9000;
    display:none; opacity:0;
    transition:opacity .25s;
}
.overlay.open { display:block; opacity:1; }

.slideover {
    position:fixed; top:0; right:-520px; bottom:0;
    width:100%; max-width:500px;
    background:var(--white);
    z-index:9001;
    display:flex; flex-direction:column;
    transition:right .3s cubic-bezier(.4,0,.2,1);
    overflow:hidden;
}
.slideover.open { right:0; }

.so-head {
    padding:1.5rem 1.75rem;
    border-bottom:1px solid var(--line);
    display:flex; align-items:center; gap:1rem;
    background:var(--ink);
    flex-shrink:0;
}
.so-head-av {
    width:48px; height:48px; border-radius:50%;
    background:linear-gradient(135deg,var(--blue) 0%,#0D1117 100%);
    display:flex; align-items:center; justify-content:center;
    font-family:'Instrument Serif',serif; font-size:1.35rem; color:white;
    flex-shrink:0;
}
.so-head-info { flex:1; }
.so-head-info h3 { font-family:'Instrument Serif',serif; font-size:1.3rem; color:white; }
.so-head-info p  { font-size:.8rem; color:rgba(255,255,255,.5); margin-top:2px; }

.so-close {
    width:34px; height:34px; border-radius:8px;
    background:rgba(255,255,255,.1); border:none;
    color:white; font-size:1.1rem; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:background .2s; flex-shrink:0;
}
.so-close:hover { background:rgba(255,255,255,.2); }

/* tabs */
.so-tabs {
    display:flex;
    border-bottom:1px solid var(--line);
    background:var(--surface);
    flex-shrink:0;
}
.so-tab {
    flex:1; padding:.85rem .5rem;
    border:none; background:transparent;
    font-family:inherit; font-size:.8rem; font-weight:600;
    color:var(--slate); cursor:pointer;
    border-bottom:2px solid transparent;
    transition:all .15s;
    display:flex; align-items:center; justify-content:center; gap:.4rem;
}
.so-tab.active { color:var(--blue); border-bottom-color:var(--blue); background:var(--white); }
.so-tab:hover:not(.active) { color:var(--ink); background:rgba(0,0,0,.03); }

/* body */
.so-body { flex:1; overflow-y:auto; padding:1.5rem 1.75rem; }

.so-section { margin-bottom:1.75rem; }
.so-section-title {
    font-size:.73rem; font-weight:700; color:var(--slate);
    text-transform:uppercase; letter-spacing:.07em;
    margin-bottom:1rem;
    padding-bottom:.5rem;
    border-bottom:1px solid var(--line);
}

/* field */
.so-field { margin-bottom:1rem; }
.so-field label {
    display:block; font-size:.8rem; font-weight:600;
    color:var(--ink-3); margin-bottom:.35rem;
}
.so-field input,
.so-field select,
.so-field textarea {
    width:100%; padding:.65rem .9rem;
    border:1.5px solid var(--line); border-radius:9px;
    font-size:.875rem; font-family:inherit; color:var(--ink);
    background:var(--white); transition:border-color .2s;
    resize:vertical;
}
.so-field input:focus,
.so-field select:focus,
.so-field textarea:focus {
    outline:none; border-color:var(--blue);
    box-shadow:0 0 0 3px rgba(24,71,237,.08);
}
.so-field input[readonly] { background:#F8FAFC; color:var(--slate); }
.so-field-row { display:grid; grid-template-columns:1fr 1fr; gap:.875rem; }

/* balance highlight box */
.bal-box {
    background:linear-gradient(135deg,var(--ink) 0%,var(--ink-2) 100%);
    border-radius:12px; padding:1.25rem 1.5rem;
    margin-bottom:1.5rem;
    display:flex; justify-content:space-between; align-items:center;
}
.bal-box-label { font-size:.78rem; color:rgba(255,255,255,.55); font-weight:500; text-transform:uppercase; letter-spacing:.05em; }
.bal-box-val   { font-family:'Instrument Serif',serif; font-size:2rem; color:white; margin-top:.25rem; }
.bal-box-right { text-align:right; }
.bal-box-sub   { font-size:.78rem; color:rgba(255,255,255,.45); margin-top:.25rem; }

/* action type chips */
.action-chips { display:grid; grid-template-columns:repeat(3,1fr); gap:.5rem; margin-bottom:1rem; }
.action-chip {
    padding:.625rem; border:1.5px solid var(--line);
    border-radius:8px; text-align:center;
    cursor:pointer; transition:all .15s;
    font-size:.78rem; font-weight:600; color:var(--slate);
}
.action-chip:hover        { border-color:var(--blue); color:var(--blue); background:var(--blue-dim); }
.action-chip.selected     { border-color:var(--blue); color:var(--blue); background:var(--blue-dim); }
.action-chip.sel-green    { border-color:var(--green); color:var(--green); background:var(--green-dim); }
.action-chip.sel-red      { border-color:var(--red);   color:var(--red);   background:var(--red-dim); }

/* message type chips */
.msg-type-chips { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
.msg-chip {
    padding:.375rem .875rem;
    border:1.5px solid var(--line); border-radius:6px;
    cursor:pointer; font-size:.78rem; font-weight:600; color:var(--slate);
    transition:all .15s;
}
.msg-chip.active { border-color:var(--blue); color:var(--blue); background:var(--blue-dim); }

/* footer */
.so-foot {
    padding:1.25rem 1.75rem;
    border-top:1px solid var(--line);
    display:flex; gap:.75rem; justify-content:flex-end;
    background:var(--white);
    flex-shrink:0;
}

/* buttons */
.btn {
    display:inline-flex; align-items:center; gap:.45rem;
    padding:.65rem 1.375rem; border-radius:9px;
    font-size:.875rem; font-weight:600; font-family:inherit;
    cursor:pointer; border:none; transition:all .2s;
    text-decoration:none;
}
.btn-ghost  { background:var(--surface); border:1.5px solid var(--line); color:var(--slate); }
.btn-ghost:hover { background:var(--line); }
.btn-primary { background:var(--ink); color:white; }
.btn-primary:hover { background:var(--blue); box-shadow:0 6px 20px rgba(24,71,237,.3); }
.btn-green  { background:var(--green); color:white; }
.btn-green:hover { filter:brightness(1.1); }
.btn-red    { background:var(--red); color:white; }
.btn-red:hover { filter:brightness(1.1); }
.btn-amber  { background:var(--amber); color:white; }
.btn-blue   { background:var(--blue); color:white; }
.btn-blue:hover { box-shadow:0 6px 20px rgba(24,71,237,.3); }

/* ─── Toast ─── */
#toast {
    position:fixed; bottom:2rem; right:2rem; z-index:99999;
    padding:.875rem 1.5rem; border-radius:10px;
    font-size:.875rem; font-weight:600; font-family:'DM Sans',sans-serif;
    pointer-events:none; opacity:0;
    transition:opacity .25s, transform .25s;
    transform:translateY(8px);
}
#toast.show { opacity:1; transform:translateY(0); }
#toast.success { background:var(--green-dim); border:1.5px solid var(--green); color:var(--green); }
#toast.error   { background:var(--red-dim);   border:1.5px solid var(--red);   color:var(--red); }
#toast.info    { background:var(--blue-dim);  border:1.5px solid var(--blue);  color:var(--blue); }

/* ─── Tab panes ─── */
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* ─── Loader ─── */
.so-loader {
    display:flex; align-items:center; justify-content:center;
    padding:3rem; color:var(--slate); font-size:.875rem; gap:.75rem;
}
.spin { animation:spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ─── History list ─── */
.hist-item {
    display:flex; align-items:center; gap:.875rem;
    padding:.875rem 0; border-bottom:1px solid #F3F5F8;
}
.hist-item:last-child { border-bottom:none; }
.hist-dot {
    width:34px; height:34px; border-radius:8px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
}
.hist-dot.dep { background:var(--green-dim); color:var(--green); }
.hist-dot.wd  { background:var(--red-dim);   color:var(--red); }
.hist-dot.roi { background:var(--blue-dim);  color:var(--blue); }

.hist-info { flex:1; }
.hist-name { font-weight:600; font-size:.85rem; color:var(--ink); }
.hist-date { font-size:.75rem; color:var(--slate); margin-top:1px; }
.hist-amt  { font-family:'Instrument Serif',serif; font-size:1rem; }
.hist-amt.pos { color:var(--green); }
.hist-amt.neg { color:var(--red); }

/* ─── Empty ─── */
.empty { text-align:center; padding:3rem 1rem; color:var(--slate); }
.empty svg { margin-bottom:.75rem; opacity:.35; }
.empty p { font-size:.875rem; }

/* ─── Responsive ─── */
@media(max-width:768px){
    .pg { padding:1rem; }
    .summary-row { flex-direction:column; }
    .toolbar { flex-direction:column; }
    .slideover { max-width:100%; }
    .so-field-row { grid-template-columns:1fr; }
}
</style>

<div class="pg">

    {{-- Page Header --}}
    <div class="pg-head">
        <div class="pg-head-left">
            <h1>Investors</h1>
            <p>Manage accounts, balances, messages &amp; tasks</p>
        </div>
        <div class="pg-head-right">
            <a href="{{ route('admin.dashboard') }}" class="btn btn-ghost">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 12L6 8L10 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                Dashboard
            </a>
        </div>
    </div>

    {{-- Summary Chips --}}
    <div class="summary-row">
        <div class="s-chip">
            <div class="s-chip-icon b">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 2C7.8 2 6 3.8 6 6C6 8.2 7.8 10 10 10C12.2 10 14 8.2 14 6C14 3.8 12.2 2 10 2Z" stroke="currentColor" stroke-width="1.5"/><path d="M3 18C3.6 15.2 6.5 13 10 13C13.5 13 16.4 15.2 17 18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div><div class="s-chip-val">{{ $totalUsers ?? 1247 }}</div><div class="s-chip-lbl">Total Investors</div></div>
        </div>
        <div class="s-chip">
            <div class="s-chip-icon g">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 18C14.4 18 18 14.4 18 10C18 5.6 14.4 2 10 2C5.6 2 2 5.6 2 10C2 14.4 5.6 18 10 18Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 6V10L13 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div><div class="s-chip-val">${{ number_format($totalInvested ?? 2840000, 0) }}</div><div class="s-chip-lbl">Total Invested</div></div>
        </div>
        <div class="s-chip">
            <div class="s-chip-icon a">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><rect x="2" y="2" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.5"/><path d="M6 10L9 13L14 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div><div class="s-chip-val">{{ $activeInvestors ?? 347 }}</div><div class="s-chip-lbl">Active Investors</div></div>
        </div>
        <div class="s-chip">
            <div class="s-chip-icon r">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M7 14L10 17L13 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M10 3V17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div><div class="s-chip-val">{{ $pendingWithdrawals ?? 8 }}</div><div class="s-chip-lbl">Pending Withdrawals</div></div>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="toolbar">
        <div class="search-wrap">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.5"/><path d="M11 11L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <input type="text" id="searchInput" class="search-input" placeholder="Search by name or email…" oninput="filterTable()">
        </div>
        <select id="statusFilter" class="filter-sel" onchange="filterTable()">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending">Pending</option>
        </select>
        <select id="sortBy" class="filter-sel" onchange="sortTable()">
            <option value="name">Sort: Name</option>
            <option value="balance">Sort: Balance</option>
            <option value="joined">Sort: Joined</option>
        </select>
    </div>

    {{-- Table --}}
    <div class="tbl-card">
        <table class="inv-table" id="investorTable">
            <thead>
                <tr>
                    <th>Investor</th>
                    <th>Balance</th>
                    <th>Invested</th>
                    <th>Plans</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="investorTableBody">
                @forelse($users ?? [] as $user)
                <tr
                    data-id="{{ $user->id }}"
                    data-name="{{ $user->name }}"
                    data-email="{{ $user->email }}"
                    data-balance="{{ $user->balance ?? 0 }}"
                    data-invested="{{ $user->total_invested ?? 0 }}"
                    data-plans="{{ $user->active_plans_count ?? 0 }}"
                    data-status="{{ $user->status ?? 'active' }}"
                    data-joined="{{ $user->created_at->format('Y-m-d') }}"
                    data-phone="{{ $user->phone ?? '' }}"
                    data-locked="{{ $user->locked_balance ?? 0 }}"
                    onclick="openSlideOver(this)"
                >
                    <td>
                        <div class="u-cell">
                            <div class="u-av">{{ strtoupper(substr($user->name,0,1)) }}</div>
                            <div>
                                <div class="u-name">{{ $user->name }}</div>
                                <div class="u-email">{{ $user->email }}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="bal">${{ number_format($user->balance ?? 0, 2) }}</span></td>
                    <td>${{ number_format($user->total_invested ?? 0, 2) }}</td>
                    <td>{{ $user->active_plans_count ?? 0 }}</td>
                    <td><span class="pill {{ $user->status ?? 'active' }}">{{ ucfirst($user->status ?? 'active') }}</span></td>
                    <td style="color:var(--slate);font-size:.8rem;">{{ $user->created_at->format('M d, Y') }}</td>
                    <td onclick="event.stopPropagation()">
                        <div class="row-actions">
                            <button class="ra-btn edit"    title="Edit"    onclick="openTab({{ $user->id }}, '{{ addslashes($user->name) }}', '{{ $user->email }}', {{ $user->balance ?? 0 }}, '{{ $user->status ?? 'active' }}', 'edit')">
                                <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M9 1.5L11.5 4L4.5 11H2V8.5L9 1.5Z" stroke="currentColor" stroke-width="1.3"/></svg>
                            </button>
                            <button class="ra-btn bal-btn" title="Balance"  onclick="openTab({{ $user->id }}, '{{ addslashes($user->name) }}', '{{ $user->email }}', {{ $user->balance ?? 0 }}, '{{ $user->status ?? 'active' }}', 'balance')">
                                <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M6.5 1.5V11.5M2 4H11M2 9H11" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                            </button>
                            <button class="ra-btn msg"     title="Message"  onclick="openTab({{ $user->id }}, '{{ addslashes($user->name) }}', '{{ $user->email }}', {{ $user->balance ?? 0 }}, '{{ $user->status ?? 'active' }}', 'message')">
                                <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M11 1H2C1.45 1 1 1.45 1 2V9C1 9.55 1.45 10 2 10H4.5L6.5 12L8.5 10H11C11.55 10 12 9.55 12 9V2C12 1.45 11.55 1 11 1Z" stroke="currentColor" stroke-width="1.2"/></svg>
                            </button>
                            @if(($user->status ?? 'active') === 'active')
                            <button class="ra-btn suspend" title="Suspend"  onclick="quickSuspend(event, {{ $user->id }})">
                                <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.3"/><path d="M4 9L9 4" stroke="currentColor" stroke-width="1.3"/></svg>
                            </button>
                            @else
                            <button class="ra-btn activate" title="Activate" onclick="quickActivate(event, {{ $user->id }})">
                                <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.3"/><path d="M4 6.5L6 8.5L9.5 5" stroke="currentColor" stroke-width="1.3"/></svg>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7"><div class="empty"><svg width="48" height="48" viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="2"/><path d="M24 14V26M24 32V34" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><p>No investors found.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>{{-- /pg --}}


{{-- ═══════════════════════════════════════
     SLIDE-OVER PANEL
     ═══════════════════════════════════════ --}}
<div class="overlay" id="soOverlay" onclick="closeSlideOver()"></div>

<div class="slideover" id="slideOver">

    {{-- Head --}}
    <div class="so-head">
        <div class="so-head-av" id="soAvatar">J</div>
        <div class="so-head-info">
            <h3 id="soName">Investor Name</h3>
            <p  id="soEmail">email@example.com</p>
        </div>
        <button class="so-close" onclick="closeSlideOver()">✕</button>
    </div>

    {{-- Tabs --}}
    <div class="so-tabs">
        <button class="so-tab active" id="tab-edit"    onclick="switchTab('edit')">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 1.5L12.5 4.5L4.5 12.5H1.5V9.5L9.5 1.5Z" stroke="currentColor" stroke-width="1.3"/></svg>
            Edit
        </button>
        <button class="so-tab" id="tab-balance" onclick="switchTab('balance')">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1V13M2 4H12M2 10H12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            Balance
        </button>
        <button class="so-tab" id="tab-message" onclick="switchTab('message')">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M12 1H2C1.45 1 1 1.45 1 2V10C1 10.55 1.45 11 2 11H5L7 13L9 11H12C12.55 11 13 10.55 13 10V2C13 1.45 12.55 1 12 1Z" stroke="currentColor" stroke-width="1.3"/></svg>
            Message
        </button>
        <button class="so-tab" id="tab-history" onclick="switchTab('history')">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1C3.7 1 1 3.7 1 7C1 10.3 3.7 13 7 13C10.3 13 13 10.3 13 7C13 3.7 10.3 1 7 1Z" stroke="currentColor" stroke-width="1.3"/><path d="M7 4V7L9.5 9.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            History
        </button>
    </div>

    {{-- Body --}}
    <div class="so-body">

        {{-- ── TAB: EDIT ── --}}
        <div class="tab-pane active" id="pane-edit">
            <form id="editForm">
                @csrf
                <input type="hidden" id="editUserId" name="user_id">

                <div class="so-section">
                    <div class="so-section-title">Personal Info</div>
                    <div class="so-field-row">
                        <div class="so-field">
                            <label>Full Name</label>
                            <input type="text" id="edit_name" name="name" placeholder="Full name">
                        </div>
                        <div class="so-field">
                            <label>Phone</label>
                            <input type="text" id="edit_phone" name="phone" placeholder="+1 234 567 890">
                        </div>
                    </div>
                    <div class="so-field">
                        <label>Email Address</label>
                        <input type="email" id="edit_email" name="email" placeholder="email@example.com">
                    </div>
                    <div class="so-field">
                        <label>Account Status</label>
                        <select id="edit_status" name="status">
                            <option value="active">✅ Active</option>
                            <option value="suspended">🚫 Suspended</option>
                            <option value="pending">⏳ Pending</option>
                        </select>
                    </div>
                </div>

                <div class="so-section">
                    <div class="so-section-title">Security</div>
                    <div class="so-field">
                        <label>New Password <span style="color:var(--slate);font-weight:400;">(leave blank to keep current)</span></label>
                        <input type="password" name="password" placeholder="Enter new password">
                    </div>
                    <div class="so-field">
                        <label>Confirm Password</label>
                        <input type="password" name="password_confirmation" placeholder="Confirm new password">
                    </div>
                </div>
            </form>
        </div>

        {{-- ── TAB: BALANCE ── --}}
        <div class="tab-pane" id="pane-balance">
            <input type="hidden" id="balUserId">

            <div class="bal-box">
                <div>
                    <div class="bal-box-label">Current Balance</div>
                    <div class="bal-box-val" id="soCurrentBal">$0.00</div>
                </div>
                <div class="bal-box-right">
                    <div class="bal-box-label">Locked / In Plans</div>
                    <div style="font-family:'Instrument Serif',serif;font-size:1.25rem;color:rgba(255,255,255,.65);" id="soLockedBal">$0.00</div>
                    <div class="bal-box-sub" id="soInvestorNameBal"></div>
                </div>
            </div>

            <div class="so-section">
                <div class="so-section-title">Adjust Balance</div>

                <div style="margin-bottom:1rem;">
                    <div style="font-size:.78rem;font-weight:600;color:var(--ink-3);margin-bottom:.5rem;">Action</div>
                    <div class="action-chips">
                        <div class="action-chip selected" id="chip-add"    onclick="selectChip('add')">➕ Add</div>
                        <div class="action-chip"          id="chip-deduct" onclick="selectChip('deduct')">➖ Deduct</div>
                        <div class="action-chip"          id="chip-set"    onclick="selectChip('set')">🔧 Set</div>
                    </div>
                    <input type="hidden" id="balAction" value="add">
                </div>

                <div class="so-field">
                    <label>Amount ($)</label>
                    <input type="number" id="balAmount" placeholder="0.00" step="0.01" min="0"
                           oninput="previewBalance()">
                </div>

                <div style="
                    background:var(--surface); border:1.5px solid var(--line);
                    border-radius:9px; padding:.875rem 1rem;
                    font-size:.875rem; color:var(--slate); margin-bottom:1rem;
                " id="balPreview">
                    Enter an amount to preview the new balance.
                </div>

                <div class="so-field">
                    <label>Internal Note</label>
                    <input type="text" id="balNote" placeholder="e.g. Manual ROI credit, bonus, correction…">
                </div>
            </div>
        </div>

        {{-- ── TAB: MESSAGE ── --}}
        <div class="tab-pane" id="pane-message">
            <input type="hidden" id="msgUserId2">

            <div class="so-section">
                <div class="so-section-title">Compose Message</div>

                <div style="margin-bottom:1rem;">
                    <div style="font-size:.78rem;font-weight:600;color:var(--ink-3);margin-bottom:.5rem;">Message Type</div>
                    <div class="msg-type-chips">
                        <div class="msg-chip active" data-val="info"    onclick="selectMsgType(this)">ℹ️ Info</div>
                        <div class="msg-chip"        data-val="success" onclick="selectMsgType(this)">✅ Success</div>
                        <div class="msg-chip"        data-val="warning" onclick="selectMsgType(this)">⚠️ Warning</div>
                        <div class="msg-chip"        data-val="task"    onclick="selectMsgType(this)">📋 Task</div>
                    </div>
                    <input type="hidden" id="msgType" value="info">
                </div>

                <div class="so-field">
                    <label>Subject / Title</label>
                    <input type="text" id="msgTitle" placeholder="e.g. Your withdrawal has been processed">
                </div>

                <div class="so-field">
                    <label>Message</label>
                    <textarea id="msgBody" rows="5" placeholder="Write your message here…"></textarea>
                </div>

                <div class="so-field">
                    <label>Delivery Channel</label>
                    <select id="msgChannel">
                        <option value="in_app">📱 In-App Only</option>
                        <option value="email">📧 Email Only</option>
                        <option value="both">📱📧 Both</option>
                    </select>
                </div>
            </div>

            {{-- Quick templates --}}
            <div class="so-section">
                <div class="so-section-title">Quick Templates</div>
                <div style="display:flex;flex-direction:column;gap:.5rem;">
                    <button class="btn btn-ghost" style="justify-content:flex-start;font-size:.8rem;"
                        onclick="fillTemplate('withdrawal_approved')">✅ Withdrawal Approved</button>
                    <button class="btn btn-ghost" style="justify-content:flex-start;font-size:.8rem;"
                        onclick="fillTemplate('investment_matured')">💰 Investment Matured</button>
                    <button class="btn btn-ghost" style="justify-content:flex-start;font-size:.8rem;"
                        onclick="fillTemplate('kyc_required')">🪪 KYC Verification Required</button>
                    <button class="btn btn-ghost" style="justify-content:flex-start;font-size:.8rem;"
                        onclick="fillTemplate('account_credited')">🎉 Account Credited</button>
                </div>
            </div>
        </div>

        {{-- ── TAB: HISTORY ── --}}
        <div class="tab-pane" id="pane-history">
            <div id="historyContent">
                <div class="so-loader">
                    <svg class="spin" width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="9" stroke="#E2E8F0" stroke-width="2.5"/><path d="M11 2C16 2 20 6 20 11" stroke="var(--blue)" stroke-width="2.5" stroke-linecap="round"/></svg>
                    Loading…
                </div>
            </div>
        </div>

    </div>{{-- /so-body --}}

    {{-- Footer --}}
    <div class="so-foot" id="soFoot">
        <button class="btn btn-ghost" onclick="closeSlideOver()">Cancel</button>
        <button class="btn btn-primary" id="soSaveBtn" onclick="handleSave()">
            <svg width="15" height="15" viewBox="0 0 15 15" fill="none"><path d="M2 7.5L6 11.5L13 3.5" stroke="white" stroke-width="1.6" stroke-linecap="round"/></svg>
            Save Changes
        </button>
    </div>

</div>{{-- /slideover --}}


@push('scripts')
<script>
// ─── State ──────────────────────────────────────────────────────────────────
let soUserId  = null;
let soUserBal = 0;
let activeTab = 'edit';
const CSRF    = () => document.querySelector('meta[name="csrf-token"]').content;

// ─── Open / close slide-over ─────────────────────────────────────────────────
function openSlideOver(row) {
    soUserId  = row.dataset.id;
    soUserBal = parseFloat(row.dataset.balance) || 0;

    const name   = row.dataset.name;
    const email  = row.dataset.email;
    const status = row.dataset.status;
    const phone  = row.dataset.phone;
    const locked = parseFloat(row.dataset.locked) || 0;

    // Head
    document.getElementById('soAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('soName').textContent   = name;
    document.getElementById('soEmail').textContent  = email;

    // Prefill edit tab
    document.getElementById('editUserId').value  = soUserId;
    document.getElementById('edit_name').value   = name;
    document.getElementById('edit_email').value  = email;
    document.getElementById('edit_phone').value  = phone;
    document.getElementById('edit_status').value = status;

    // Balance tab
    document.getElementById('balUserId').value           = soUserId;
    document.getElementById('soCurrentBal').textContent  = '$' + fmt(soUserBal);
    document.getElementById('soLockedBal').textContent   = '$' + fmt(locked);
    document.getElementById('soInvestorNameBal').textContent = name;
    document.getElementById('balAmount').value           = '';
    document.getElementById('balNote').value             = '';
    document.getElementById('balPreview').textContent    = 'Enter an amount to preview the new balance.';
    selectChip('add', false);

    // Message tab
    document.getElementById('msgUserId2').value = soUserId;
    document.getElementById('msgTitle').value   = '';
    document.getElementById('msgBody').value    = '';

    // Switch to edit tab by default
    switchTab('edit');

    document.getElementById('soOverlay').classList.add('open');
    document.getElementById('slideOver').classList.add('open');
}

function openTab(id, name, email, balance, status, tab) {
    // Build a fake row-like object from inline params
    const fakeRow = {
        dataset: { id: id, name: name, email: email, balance: balance,
                   status: status, phone: '', locked: 0, invested: 0, plans: 0 }
    };
    openSlideOver(fakeRow);
    switchTab(tab);
}

function closeSlideOver() {
    document.getElementById('soOverlay').classList.remove('open');
    document.getElementById('slideOver').classList.remove('open');
}

// ─── Tabs ────────────────────────────────────────────────────────────────────
function switchTab(tab) {
    activeTab = tab;
    ['edit','balance','message','history'].forEach(t => {
        document.getElementById('tab-' + t).classList.toggle('active', t === tab);
        document.getElementById('pane-' + t).classList.toggle('active', t === tab);
    });

    // Update footer buttons
    const saveBtn = document.getElementById('soSaveBtn');
    if (tab === 'edit') {
        saveBtn.textContent = '';
        saveBtn.innerHTML   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><path d="M2 7.5L6 11.5L13 3.5" stroke="white" stroke-width="1.6" stroke-linecap="round"/></svg> Save Changes';
        saveBtn.className   = 'btn btn-primary';
    } else if (tab === 'balance') {
        saveBtn.innerHTML   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><path d="M7.5 1V14M1 5H14M1 10H14" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg> Update Balance';
        saveBtn.className   = 'btn btn-amber';
    } else if (tab === 'message') {
        saveBtn.innerHTML   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><path d="M13 2L1 6.5L5.5 8.5L7.5 13L13 2Z" stroke="white" stroke-width="1.4" stroke-linejoin="round"/></svg> Send Message';
        saveBtn.className   = 'btn btn-green';
    } else if (tab === 'history') {
        saveBtn.style.display = 'none';
        loadHistory();
        return;
    }
    saveBtn.style.display = '';
}

// ─── Save router ─────────────────────────────────────────────────────────────
function handleSave() {
    if (activeTab === 'edit')    saveEdit();
    if (activeTab === 'balance') saveBalance();
    if (activeTab === 'message') sendMessage();
}

// ─── Save Edit ───────────────────────────────────────────────────────────────
function saveEdit() {
    const fd = new FormData(document.getElementById('editForm'));
    fetch(`/admin/users/${soUserId}/update`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': CSRF() },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            toast('Investor updated successfully!', 'success');
            // Update table row live
            updateTableRow(soUserId, {
                name:   document.getElementById('edit_name').value,
                email:  document.getElementById('edit_email').value,
                status: document.getElementById('edit_status').value,
            });
            closeSlideOver();
        } else {
            toast(res.message || 'Update failed.', 'error');
        }
    })
    .catch(() => toast('Network error.', 'error'));
}

// ─── Balance chip selection ──────────────────────────────────────────────────
function selectChip(action, preview = true) {
    document.getElementById('balAction').value = action;
    ['add','deduct','set'].forEach(a => {
        const chip = document.getElementById('chip-' + a);
        chip.className = 'action-chip';
        if (a === action) {
            chip.className += action === 'deduct' ? ' sel-red' : action === 'set' ? ' sel-green' : ' selected';
        }
    });
    if (preview) previewBalance();
}

function previewBalance() {
    const action = document.getElementById('balAction').value;
    const amount = parseFloat(document.getElementById('balAmount').value) || 0;
    const prev   = document.getElementById('balPreview');

    if (!amount) { prev.textContent = 'Enter an amount to preview the new balance.'; return; }

    let newBal;
    if (action === 'add')    newBal = soUserBal + amount;
    if (action === 'deduct') newBal = soUserBal - amount;
    if (action === 'set')    newBal = amount;

    const diff = newBal - soUserBal;
    const sign = diff >= 0 ? '+' : '';
    prev.innerHTML = `
        New balance: <strong style="color:var(--ink);font-size:1rem;">$${fmt(newBal)}</strong>
        &nbsp;·&nbsp;
        <span style="color:${diff >= 0 ? 'var(--green)' : 'var(--red)'};">${sign}$${fmt(Math.abs(diff))}</span>
    `;
}

// ─── Save Balance ────────────────────────────────────────────────────────────
function saveBalance() {
    const amount = parseFloat(document.getElementById('balAmount').value);
    if (!amount) { toast('Please enter an amount.', 'error'); return; }

    fetch(`/admin/users/${soUserId}/update-balance`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF() },
        body: JSON.stringify({
            action: document.getElementById('balAction').value,
            amount: amount,
            note:   document.getElementById('balNote').value,
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            soUserBal = res.new_balance;
            document.getElementById('soCurrentBal').textContent = '$' + fmt(soUserBal);
            updateTableRow(soUserId, { balance: soUserBal });
            document.getElementById('balAmount').value = '';
            document.getElementById('balNote').value   = '';
            document.getElementById('balPreview').textContent = 'Balance updated successfully.';
            toast('Balance updated!', 'success');
        } else {
            toast(res.message || 'Failed to update balance.', 'error');
        }
    })
    .catch(() => toast('Network error.', 'error'));
}

// ─── Message type ─────────────────────────────────────────────────────────────
function selectMsgType(el) {
    document.querySelectorAll('.msg-chip').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('msgType').value = el.dataset.val;
}

// ─── Quick Templates ──────────────────────────────────────────────────────────
const templates = {
    withdrawal_approved: {
        type: 'success', title: 'Withdrawal Approved',
        body: 'Your withdrawal request has been reviewed and approved. The funds will be reflected in your account within 1-3 business days. Thank you for investing with us.'
    },
    investment_matured: {
        type: 'success', title: 'Investment Plan Matured',
        body: 'Congratulations! Your investment plan has reached maturity. Your principal and returns have been credited to your wallet balance. You may reinvest or withdraw at any time.'
    },
    kyc_required: {
        type: 'warning', title: 'KYC Verification Required',
        body: 'To continue using your account and making withdrawals, we require you to complete identity verification (KYC). Please upload the required documents in your profile settings.'
    },
    account_credited: {
        type: 'success', title: 'Your Account Has Been Credited',
        body: 'Great news! Your account has been credited with a bonus. Log in to your dashboard to view your updated balance. Thank you for being a valued investor.'
    },
};

function fillTemplate(key) {
    const t = templates[key];
    document.getElementById('msgTitle').value = t.title;
    document.getElementById('msgBody').value  = t.body;
    document.getElementById('msgType').value  = t.type;
    document.querySelectorAll('.msg-chip').forEach(c => {
        c.classList.toggle('active', c.dataset.val === t.type);
    });
}

// ─── Send Message ─────────────────────────────────────────────────────────────
function sendMessage() {
    const title   = document.getElementById('msgTitle').value.trim();
    const body    = document.getElementById('msgBody').value.trim();
    if (!title || !body) { toast('Please fill in subject and message.', 'error'); return; }

    fetch('/admin/notifications/send', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF() },
        body: JSON.stringify({
            user_id: soUserId,
            type:    document.getElementById('msgType').value,
            title:   title,
            message: body,
            channel: document.getElementById('msgChannel').value,
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            toast('Message sent!', 'success');
            document.getElementById('msgTitle').value = '';
            document.getElementById('msgBody').value  = '';
            closeSlideOver();
        } else {
            toast(res.message || 'Failed to send.', 'error');
        }
    })
    .catch(() => toast('Network error.', 'error'));
}

// ─── Load Transaction History ─────────────────────────────────────────────────
function loadHistory() {
    const container = document.getElementById('historyContent');
    container.innerHTML = `<div class="so-loader"><svg class="spin" width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="9" stroke="#E2E8F0" stroke-width="2.5"/><path d="M11 2C16 2 20 11 20 11" stroke="var(--blue)" stroke-width="2.5" stroke-linecap="round"/></svg> Loading…</div>`;

    fetch(`/admin/users/${soUserId}/transactions`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF() }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.transactions || res.transactions.length === 0) {
            container.innerHTML = `<div class="empty"><svg width="44" height="44" viewBox="0 0 44 44" fill="none"><circle cx="22" cy="22" r="20" stroke="currentColor" stroke-width="1.5"/><path d="M22 12V24M22 30V32" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg><p>No transactions yet.</p></div>`;
            return;
        }
        container.innerHTML = res.transactions.map(t => {
            const isPos = t.type === 'deposit' || t.type === 'profit';
            return `
            <div class="hist-item">
                <div class="hist-dot ${t.type === 'deposit' ? 'dep' : t.type === 'withdrawal' ? 'wd' : 'roi'}">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        ${t.type === 'withdrawal'
                            ? '<path d="M7 2V12M7 12L4 9M7 12L10 9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>'
                            : '<path d="M7 12V2M7 2L4 5M7 2L10 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>'}
                    </svg>
                </div>
                <div class="hist-info">
                    <div class="hist-name">${ucfirst(t.type)}${t.plan_name ? ' · ' + t.plan_name : ''}</div>
                    <div class="hist-date">${t.date} · <span style="padding:.15rem .45rem;border-radius:4px;font-size:.7rem;font-weight:700;background:${t.status === 'completed' ? 'var(--green-dim)' : t.status === 'pending' ? 'var(--amber-dim)' : 'var(--red-dim)'};color:${t.status === 'completed' ? 'var(--green)' : t.status === 'pending' ? 'var(--amber)' : 'var(--red)'};">${ucfirst(t.status)}</span></div>
                </div>
                <div class="hist-amt ${isPos ? 'pos' : 'neg'}">${isPos ? '+' : '-'}$${fmt(t.amount)}</div>
            </div>`;
        }).join('');
    })
    .catch(() => {
        container.innerHTML = `<div class="empty"><p>Failed to load transactions.</p></div>`;
    });
}

// ─── Quick suspend / activate ─────────────────────────────────────────────────
function quickSuspend(e, id) {
    e.stopPropagation();
    if (!confirm('Suspend this investor?')) return;
    fetch(`/admin/users/${id}/suspend`, {
        method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF() }
    }).then(r => r.json()).then(res => {
        if (res.success) { toast('User suspended.', 'info'); location.reload(); }
    });
}

function quickActivate(e, id) {
    e.stopPropagation();
    if (!confirm('Activate this investor?')) return;
    fetch(`/admin/users/${id}/activate`, {
        method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF() }
    }).then(r => r.json()).then(res => {
        if (res.success) { toast('User activated.', 'success'); location.reload(); }
    });
}

// ─── Live update table row ────────────────────────────────────────────────────
function updateTableRow(id, data) {
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (!row) return;
    if (data.name)    { row.dataset.name = data.name;  row.querySelector('.u-name').textContent  = data.name;  row.querySelector('.u-av').textContent = data.name.charAt(0).toUpperCase(); }
    if (data.email)   { row.dataset.email = data.email; row.querySelector('.u-email').textContent = data.email; }
    if (data.status)  { row.dataset.status = data.status; row.querySelector('.pill').className = 'pill ' + data.status; row.querySelector('.pill').textContent = ucfirst(data.status); }
    if (data.balance !== undefined) { row.dataset.balance = data.balance; row.querySelector('.bal').textContent = '$' + fmt(data.balance); }
}

// ─── Table filter / sort ──────────────────────────────────────────────────────
function filterTable() {
    const q      = document.getElementById('searchInput').value.toLowerCase();
    const status = document.getElementById('statusFilter').value;
    document.querySelectorAll('#investorTableBody tr[data-id]').forEach(row => {
        const matchQ = !q || row.dataset.name.toLowerCase().includes(q) || row.dataset.email.toLowerCase().includes(q);
        const matchS = !status || row.dataset.status === status;
        row.style.display = (matchQ && matchS) ? '' : 'none';
    });
}

function sortTable() {
    const by   = document.getElementById('sortBy').value;
    const tbody = document.getElementById('investorTableBody');
    const rows  = Array.from(tbody.querySelectorAll('tr[data-id]'));
    rows.sort((a, b) => {
        if (by === 'balance') return parseFloat(b.dataset.balance) - parseFloat(a.dataset.balance);
        if (by === 'joined')  return new Date(b.dataset.joined) - new Date(a.dataset.joined);
        return a.dataset.name.localeCompare(b.dataset.name);
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ─── Toast ────────────────────────────────────────────────────────────────────
function toast(msg, type = 'info') {
    let el = document.getElementById('toast');
    if (!el) { el = document.createElement('div'); el.id = 'toast'; document.body.appendChild(el); }
    el.textContent = msg;
    el.className   = type;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 3200);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function fmt(n)    { return parseFloat(n).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function ucfirst(s){ return s.charAt(0).toUpperCase() + s.slice(1); }
</script>
@endpush
@endsection