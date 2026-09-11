<nav class="navbar">
    <div class="nav-container">
        <!-- Logo -->
        <a href="{{ Auth::check() ? (Auth::user()->role === 'admin' ? route('admin.dashboard') : route('investor-investment.dashboard')) : route('home') }}" class="logo">
            Smart<span>System</span>
        </a>
        
        @guest
            <!-- Guest Navigation -->
            <ul class="nav-menu">
                <li><a href="{{ route('home') }}" class="nav-link {{ Request::is('/') ? 'active' : '' }}">Home</a></li>
                <li><a href="{{ route('about') }}" class="nav-link {{ Request::is('about') ? 'active' : '' }}">About</a></li>
                <li><a href="{{ route('plans') }}" class="nav-link {{ Request::is('plans') ? 'active' : '' }}">Plans</a></li>
                <li><a href="{{ route('how-it-works') }}" class="nav-link {{ Request::is('how-it-works') ? 'active' : '' }}">How It Works</a></li>
                <li><a href="{{ route('faq') }}" class="nav-link {{ Request::is('faq') ? 'active' : '' }}">FAQ</a></li>
                <li><a href="{{ route('contact') }}" class="nav-link {{ Request::is('contact') ? 'active' : '' }}">Contact</a></li>
            </ul>
            <div class="nav-buttons">
                <a href="{{ route('login') }}" class="btn btn-secondary">Login</a>
                <a href="{{ route('register') }}" class="btn btn-primary">Register</a>
            </div>
        @else
            <!-- Authenticated User Navigation -->
            <div class="nav-buttons" style="display: flex; align-items: center; gap: 2rem;">
              @php
    $unreadCount = \App\Models\Notification::where('user_id', auth()->id())->where('read', false)->count();
@endphp

<div class="notification-badge" onclick="toggleNotifications()" style="position: relative; cursor: pointer;">
    <span style="font-size: 1.5rem;">🔔</span>
    @if($unreadCount > 0)
        <span class="notification-count" style="position: absolute; top: -8px; right: -8px; background: var(--danger); color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700;">
            {{ $unreadCount }}
        </span>
    @endif
</div>
                    
                    <!-- Notifications Panel -->
                    <div id="notificationsPanel" style="display: none; position: absolute; top: 100%; right: 0; width: 360px; max-height: 500px; overflow-y: auto; background: white; border-radius: 12px; box-shadow: 0 8px 32px var(--shadow-lg); margin-top: 1rem; z-index: 1000;">
                        <div style="padding: 1.5rem; border-bottom: 1px solid var(--border); font-weight: 600;">
                            Notifications
                        </div>
                        @forelse(auth()->user()->notifications->take(5) as $notification)
                            <div style="padding: 1.5rem; border-bottom: 1px solid var(--border); cursor: pointer; {{ $notification->read_at ? '' : 'background: rgba(212, 175, 55, 0.05);' }}" onclick="markAsRead('{{ $notification->id }}')">
                                <div style="font-weight: 600; margin-bottom: 0.5rem;">{{ $notification->data['title'] ?? 'Notification' }}</div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary);">{{ $notification->data['message'] ?? '' }}</div>
                                <div style="font-size: 0.75rem; color: var(--text-light); margin-top: 0.5rem;">{{ $notification->created_at->diffForHumans() }}</div>
                            </div>
                        @empty
                            <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                                No notifications
                            </div>
                        @endforelse
                    </div>
                </div>
                <span style="color: var(--text-secondary);">Welcome, {{ Auth::user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}" style="display: inline;">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Logout</button>
                </form>
            </div>
        @endguest
    </div>
</nav>

@push('scripts')
<script>
    function toggleNotifications() {
        const panel = document.getElementById('notificationsPanel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    }
    
    function markAsRead(notificationId) {
        fetch(`/notifications/${notificationId}/mark-read`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
            }
        });
    }
    
    // Close notifications when clicking outside
    document.addEventListener('click', function(event) {
        const panel = document.getElementById('notificationsPanel');
        const badge = event.target.closest('.notification-badge');
        if (!badge && panel) {
            panel.style.display = 'none';
        }
    });
</script>
@endpush