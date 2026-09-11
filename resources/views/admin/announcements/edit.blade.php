@extends('layouts.dashboard')

@section('title', 'Edit Announcement')

@section('content')

<div style="background-color: #F9FAFB; min-height: 100vh; padding: 2rem;">
    
    <!-- Header -->
    <div style="margin-bottom: 2rem;">
        <a href="{{ route('admin.announcements.index') }}" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #2563EB; text-decoration: none; font-weight: 600; margin-bottom: 1rem;">
            ← Back to Announcements
        </a>
        <h1 style="font-size: 2.5rem; font-weight: 700; color: #1E3A8A; margin: 0; font-family: 'Crimson Pro', serif;">
            Edit Announcement
        </h1>
        <p style="color: #6B7280; margin-top: 0.5rem;">Update announcement details</p>
    </div>

    <!-- Form Card -->
    <div style="background: white; border-radius: 16px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; max-width: 900px;">
        
        <form action="{{ route('admin.announcements.update', $announcement) }}" method="POST">
            @csrf
            @method('PUT')
            
            <!-- Title -->
            <div style="margin-bottom: 2rem;">
                <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">
                    Announcement Title
                    <span style="color: #EF4444;">*</span>
                </label>
                <input type="text" name="title" value="{{ $announcement->title }}" required style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; transition: all 0.3s;">
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
                <textarea name="content" required rows="6" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; font-family: 'DM Sans', sans-serif; transition: all 0.3s;">{{ $announcement->content }}</textarea>
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
                    <option value="low" {{ $announcement->priority === 'low' ? 'selected' : '' }}>🟢 Low Priority</option>
                    <option value="medium" {{ $announcement->priority === 'medium' ? 'selected' : '' }}>🟡 Medium Priority</option>
                    <option value="high" {{ $announcement->priority === 'high' ? 'selected' : '' }}>🔴 High Priority</option>
                </select>
                @error('priority')
                    <p style="color: #EF4444; font-size: 0.875rem; margin-top: 0.5rem;">{{ $message }}</p>
                @enderror
            </div>

            <!-- Status -->
            <div style="margin-bottom: 2rem;">
                <label style="display: block; font-weight: 600; color: #1F2937; margin-bottom: 0.75rem;">
                    Status
                </label>
                <select name="is_published" style="width: 100%; padding: 0.875rem; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 1rem; background: white; cursor: pointer;">
                    <option value="1" {{ $announcement->is_published ? 'selected' : '' }}>✅ Published</option>
                    <option value="0" {{ !$announcement->is_published ? 'selected' : '' }}>⏸️ Draft</option>
                </select>
            </div>

            <!-- Info Box -->
            <div style="padding: 1.5rem; background: linear-gradient(135deg, #DBEAFE 0%, #CFFAFE 100%); border-radius: 12px; border-left: 4px solid #2563EB; margin-bottom: 2rem;">
                <p style="color: #1E40AF; margin: 0; font-weight: 600;">
                    💡 Note
                </p>
                <p style="color: #1E40AF; font-size: 0.95rem; margin: 0.5rem 0 0 0; line-height: 1.6;">
                    This announcement was created on {{ $announcement->created_at->format('M d, Y at g:i A') }} and has been viewed by {{ $announcement->notifications()->count() }} investors.
                </p>
            </div>

            <!-- Buttons -->
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <a href="{{ route('admin.announcements.index') }}" style="padding: 0.875rem 2rem; background: #F3F4F6; color: #1F2937; border-radius: 10px; text-decoration: none; font-weight: 600; transition: all 0.3s; border: 2px solid #E5E7EB;">
                    Cancel
                </a>
                <button type="submit" style="padding: 0.875rem 2rem; background: linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%); color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
                    💾 Save Changes
                </button>
            </div>
        </form>
    </div>

</div>

@endsection