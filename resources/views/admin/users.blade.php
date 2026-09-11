@extends('layouts.app')

@section('title', 'User Management - Admin')

@section('content')
<div class="dashboard">
    <div class="dashboard-header">
        <h1 class="welcome-text">User Management</h1>
        <p class="dashboard-subtitle">Manage all investor accounts</p>
    </div>

    <!-- Search and Filters -->
    <div class="dashboard-card" style="margin-bottom: 1.5rem;">
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <input type="text" class="form-input" placeholder="Search by name or email..." style="flex: 1; min-width: 250px;">
            <select class="form-input" style="max-width: 180px;">
                <option>All Status</option>
                <option>Active</option>
                <option>Suspended</option>
            </select>
            <select class="form-input" style="max-width: 180px;">
                <option>All Tiers</option>
                <option>Starter</option>
                <option>Professional</option>
                <option>Elite</option>
            </select>
            <button class="btn btn-primary">Search</button>
        </div>
    </div>

    <!-- Users Table -->
    <div class="dashboard-card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Balance</th>
                    <th>Tier</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users ?? [] as $user)
                    <tr>
                        <td style="font-family: monospace;">#{{ $user->id }}</td>
                        <td style="font-weight: 600;">{{ $user->name }}</td>
                        <td style="font-size: 0.9rem;">{{ $user->email }}</td>
                        <td style="font-weight: 700; color: var(--success);">${{ number_format($user->balance, 2) }}</td>
                        <td>
                            <span class="badge badge-{{ $user->tier === 'elite' ? 'warning' : 'success' }}">
                                {{ ucfirst($user->tier ?? 'starter') }}
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-{{ $user->status === 'active' ? 'success' : 'danger' }}">
                                {{ $user->status }}
                            </span>
                        </td>
                        <td style="font-size: 0.85rem;">{{ $user->created_at->format('M d, Y') }}</td>
                        <td>
                            <div style="display: flex; gap: 0.5rem;">
                                <button onclick="viewUser({{ $user->id }})" class="btn btn-secondary btn-small">View</button>
                                @if($user->status === 'active')
                                    <form action="{{ route('admin.users.suspend', $user) }}" method="POST" style="display: inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-small" style="background: var(--danger); color: white; border: none;">Suspend</button>
                                    </form>
                                @else
                                    <form action="{{ route('admin.users.activate', $user) }}" method="POST" style="display: inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-small" style="background: var(--success); color: white; border: none;">Activate</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3rem;">No users found</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination -->
        <div style="margin-top: 2rem;">
            {{ $users->links() ?? '' }}
        </div>
    </div>
</div>

@push('scripts')
<script>
    function viewUser(userId) {
        alert('View user details for ID: ' + userId);
        // Implement user details modal or redirect
    }
</script>
@endpush
@endsection