@extends('layouts.dashboard')

@section('title', 'Create Announcement')

@section('content')

<div style="background-color: #F9FAFB; min-height: 100vh; padding: 2rem;">
    
    <!-- Header -->
    <div style="margin-bottom: 2rem;">
        <a href="{{ route('admin.announcements.index') }}" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #2563EB; text-decoration: none; font-weight: 600; margin-bottom: 1rem;">
            ← Back to Announcements
        </a>
        <h1 style="font-size: 2.5rem; font-weight: 700; color: #1E3A8A; margin: 0; font-family: 'Crimson Pro', serif;">
            Create Announcement
        </h1>
        <p style="color: #6B7280; margin-top: 0.5rem;">Send a notification to all investors</p>
    </div>

    <!-- Form Card -->
    <div style="background: white; border-radius: 16px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; max-width: 900px;">
        
        <form action="{{ route('admin.announcements.store') }}" method="POST">
            @csrf
            
            <!-- Title -->
            <div style="margin-bottom: 2rem;">
                <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">
                    Announcement Title
                    <span style="color: #EF4444;">*</span>
                </label>
                <input type="text" name="title" required style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; transition: all 0.3s;" placeholder="Enter announcement title">
                @error('title')
                    <p style="color: #EF4444; font-size: 0.875rem; margin-top: 0.5rem;">{{ $message }}</p>
                @enderror
            </div>

            <!-- Content -->
            <div style="margin-bottom: 2rem;">
                <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">
                    Content
                    <span style="color: #EF4444;">*</span>
                </label>
                <textarea name="content" required rows="6" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; font-family: 'DM Sans', sans-serif; transition: all 0.3s;" placeholder="Write your announcement..."></textarea>
                @error('content')
                    <p style="color: #EF4444; font-size: 0.875rem; margin-top: 0.5rem;">{{ $message }}</p>
                @enderror
            </div>

            <!-- Priority -->
            <div style="margin-bottom: 2rem;">
                <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">
                    Priority
                    <span style="color: #EF4444;">*</span>
                </label>
                <select name="priority" required style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; background: white; cursor: pointer;">
                    <option value="low">🟢 Low Priority</option>
                    <option value="medium" selected>🟡 Medium Priority</option>
                    <option value="high">🔴 High Priority</option>
                </select>
                @error('priority')
                    <p style="color: #EF4444; font-size: 0.875rem; margin-top: 0.5rem;">{{ $message }}</p>
                @enderror
            </div>

            <!-- Send To -->
            <div style="margin-bottom: 2rem;">
                <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">
                    Send To
                    <span style="color: #EF4444;">*</span>
                </label>
                <select name="send_to" required style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; background: white; cursor: pointer;">
                    <option value="all">👥 All Investors</option>
                    <option value="active">✅ Active Investors Only</option>
                    <option value="vip">⭐ VIP Investors Only</option>
                </select>
                @error('send_to')
                    <p style="color: #EF4444; font-size: 0.875rem; margin-top: 0.5rem;">{{ $message }}</p>
                @enderror
            </div>

            <!-- Info Box -->
            <div style="padding: 1.5rem; background: linear-gradient(135deg, #DBEAFE 0%, #CFFAFE 100%); border-radius: 12px; border-left: 4px solid #2563EB; margin-bottom: 2rem;">
                <p style="color: #1E40AF; margin: 0; font-weight: 600;">
                    💡 Pro Tip
                </p>
                <p style="color: #1E40AF; font-size: 0.95rem; margin: 0.5rem 0 0 0; line-height: 1.6;">
                    High priority announcements will appear at the top of investor notifications. Use them for urgent updates.
                </p>
            </div>

            <!-- Buttons -->
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <a href="{{ route('admin.announcements.index') }}" style="padding: 0.875rem 2rem; background: #F3F4F6; color: #1F2937; border-radius: 10px; text-decoration: none; font-weight: 600; transition: all 0.3s; border: 2px solid #E5E7EB;">
                    Cancel
                </a>
                <button type="submit" style="padding: 0.875rem 2rem; background: linear-gradient(135deg, #10B981 0%, #059669 100%); color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);">
                    📢 Publish Announcement
                </button>
            </div>
        </form>
    </div>

</div>

@endsection