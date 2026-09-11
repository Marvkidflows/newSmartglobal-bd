@extends('layouts.app')

@section('title', 'Send Notifications - Admin')

@section('content')
<div class="dashboard">
    <div class="dashboard-header">
        <h1 class="welcome-text">Send Notifications</h1>
        <p class="dashboard-subtitle">Broadcast messages to users</p>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <!-- Send Notification Form -->
        <div class="dashboard-card">
            <h3 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--primary);">Compose Notification</h3>
            
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            <form action="{{ route('admin.notifications.send') }}" method="POST">
                @csrf
                
                <div class="form-group">
                    <label class="form-label">Recipients</label>
                    <select name="recipients" class="form-input" required>
                        <option value="all">All Users</option>
                        <option value="starter">Starter Tier Only</option>
                        <option value="professional">Professional Tier Only</option>
                        <option value="elite">Elite Tier Only</option>
                        <option value="active">Active Users Only</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Notification Title</label>
                    <input type="text" name="title" class="form-input" placeholder="e.g., System Update" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-input" rows="6" placeholder="Your notification message..." required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-input">
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    Send Notification
                </button>
            </form>
        </div>

        <!-- Recent Notifications -->
        <div class="dashboard-card">
            <h3 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--primary);">Recent Notifications</h3>
            
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                @forelse($recentNotifications ?? [] as $notification)
                    <div style="padding: 1.5rem; background: var(--bg-primary); border-radius: 12px; border-left: 4px solid var(--primary);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                            <strong style="color: var(--primary);">{{ $notification->title }}</strong>
                            <span style="font-size: 0.75rem; color: var(--text-secondary);">{{ $notification->created_at->diffForHumans() }}</span>
                        </div>
                        <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            {{ Str::limit($notification->message, 100) }}
                        </p>
                        <div style="display: flex; gap: 1rem; font-size: 0.8rem; color: var(--text-secondary);">
                            <span>To: <strong>{{ $notification->recipients_count ?? 0 }} users</strong></span>
                            <span>•</span>
                            <span>Read: <strong>{{ $notification->read_count ?? 0 }}</strong></span>
                        </div>
                    </div>
                @empty
                    <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📧</div>
                        <p>No notifications sent yet</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Templates -->
    <div class="dashboard-card" style="margin-top: 2rem;">
        <h3 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--primary);">Notification Templates</h3>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
            <div style="padding: 1.5rem; border: 2px dashed var(--border); border-radius: 12px; cursor: pointer; transition: all 0.3s;" onclick="useTemplate('welcome')">
                <h4 style="font-weight: 700; margin-bottom: 0.5rem;">Welcome Message</h4>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">New user welcome notification</p>
            </div>
            
            <div style="padding: 1.5rem; border: 2px dashed var(--border); border-radius: 12px; cursor: pointer; transition: all 0.3s;" onclick="useTemplate('maintenance')">
                <h4 style="font-weight: 700; margin-bottom: 0.5rem;">Maintenance Alert</h4>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">Scheduled maintenance notification</p>
            </div>
            
            <div style="padding: 1.5rem; border: 2px dashed var(--border); border-radius: 12px; cursor: pointer; transition: all 0.3s;" onclick="useTemplate('bonus')">
                <h4 style="font-weight: 700; margin-bottom: 0.5rem;">Bonus Announcement</h4>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">Special bonus or promotion</p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function useTemplate(type) {
        const templates = {
            welcome: {
                title: 'Welcome to Smart System!',
                message: 'Thank you for joining Smart System Investment. We\'re excited to help you grow your wealth. Start by exploring your dashboard and completing your first task!'
            },
            maintenance: {
                title: 'Scheduled Maintenance',
                message: 'We will be performing scheduled maintenance on Sunday from 3:00 AM to 5:00 AM EST. Withdrawals will be temporarily paused during this time.'
            },
            bonus: {
                title: 'Special Bonus Available!',
                message: 'Complete 5 tasks this week and earn a special $50 bonus! This offer is valid for all active investors.'
            }
        };
        
        const template = templates[type];
        document.querySelector('input[name="title"]').value = template.title;
        document.querySelector('textarea[name="message"]').value = template.message;
        
        // Scroll to form
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
</script>
@endpush
@endsection