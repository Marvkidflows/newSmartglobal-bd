<!-- Dashboard Navbar -->
<nav style="background-color: #FFFFFF; border-bottom: 1px solid #E5E7EB; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1); padding: 0;">
    <div style="max-width: 1400px; margin: 0 auto; padding: 0 2rem; display: flex; justify-content: space-between; align-items: center; height: 70px;">
        
        <!-- Logo -->
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <a href="{{ route('home') }}" style="font-size: 1.5rem; font-weight: 700; color: #1E3A8A; text-decoration: none; font-family: 'Crimson Pro', serif;">
                SmartSystem
            </a>
        </div>
        
        <!-- Nav Links (Center) -->
        <div style="display: flex; gap: 2rem; align-items: center;">
            @if(auth()->user()->role === 'admin')
                <a href="{{ route('admin.dashboard') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Dashboard
                </a>
                <a href="{{ route('admin.users.index') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Users
                </a>
                <a href="{{ route('admin.investments.index') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Investments
                </a>
                <a href="{{ route('admin.messages.index') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Messages
                </a>
                <a href="{{ route('admin.announcements.index') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Announcements
                </a>
            @else
                <a href="{{ route('investor-investment.dashboard') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Dashboard
                </a>
                <a href="{{ route('investor-investment.investments.plans') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Invest
                </a>
                <a href="{{ route('investor-investment.deposits.index') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Deposits
                </a>
                <a href="{{ route('investor-investment.withdrawals.index') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Withdrawals
                </a>
                <a href="{{ route('investor-investment.messages.index') }}" style="text-decoration: none; color: #6B7280; font-weight: 500; transition: color 0.3s;">
                    Messages
                </a>
            @endif
        </div>
        
        <!-- User Menu (Right) -->
        <div style="display: flex; align-items: center; gap: 1.5rem;">
            
            <!-- Notifications (if investor) -->
            @if(auth()->user()->role === 'investor')
                <a href="{{ route('investor-investment.notifications.index') }}" style="position: relative; text-decoration: none;">
                    <svg style="width: 24px; height: 24px; color: #6B7280;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                    </svg>
                    @php
                        $unreadCount = \App\Models\Notification::where('user_id', auth()->id())->where('read', false)->count();
                    @endphp
                    @if($unreadCount > 0)
                        <span style="position: absolute; top: -5px; right: -5px; background-color: #EF4444; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 600;">
                            {{ $unreadCount }}
                        </span>
                    @endif
                </a>
            @endif
            
            <!-- User Avatar & Dropdown -->
            <div style="position: relative; display: flex; align-items: center;">
                <button onclick="toggleUserMenu()" style="background: none; border: none; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                    <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                        {{ substr(auth()->user()->name, 0, 1) }}
                    </div>
                    <svg style="width: 16px; height: 16px; color: #6B7280;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                    </svg>
                </button>
                
                <!-- Dropdown Menu -->
                <div id="userMenu" style="position: absolute; top: 50px; right: 0; background: white; border: 1px solid #E5E7EB; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); min-width: 200px; display: none; z-index: 1000;">
                    <div style="padding: 1rem; border-bottom: 1px solid #E5E7EB;">
                        <p style="margin: 0; font-weight: 600; color: #1F2937;">{{ auth()->user()->name }}</p>
                        <p style="margin: 0.25rem 0 0 0; font-size: 0.875rem; color: #6B7280;">{{ ucfirst(auth()->user()->role) }}</p>
                    </div>
                    <!-- Profile Links - Only show for investors -->
@if(auth()->user()->role === 'investor')
    <a href="{{ route('investor-investment.profile.show') }}" style="display: block; padding: 0.75rem 1rem; color: #374151; text-decoration: none; transition: background 0.2s;">
        Profile
    </a>
    <a href="{{ route('investor-investment.profile.edit') }}" style="display: block; padding: 0.75rem 1rem; color: #374151; text-decoration: none; transition: background 0.2s;">
        Settings
    </a>
@else
    <!-- Admin has no profile page -->
    <div style="padding: 0.75rem 1rem; color: #6B7280; font-size: 0.875rem;">
        Admin Panel
    </div>
@endif
                    
                    <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
                        @csrf
                        <button type="submit" style="width: 100%; text-align: left; padding: 0.75rem 1rem; border: none; background: none; color: #EF4444; cursor: pointer; text-decoration: none; transition: background 0.2s;">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</nav>

<script>
function toggleUserMenu() {
    const menu = document.getElementById('userMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    
    // Close when clicking outside
    document.addEventListener('click', function(event) {
        if (!event.target.closest('[onclick="toggleUserMenu()"]') && !event.target.closest('#userMenu')) {
            menu.style.display = 'none';
        }
    });
}
</script>

<style>
    a:hover {
        color: #1E3A8A !important;
    }
    
    #userMenu a:hover {
        background-color: #F3F4F6;
    }
    
    #userMenu button:hover {
        background-color: #F3F4F6;
    }
</style>