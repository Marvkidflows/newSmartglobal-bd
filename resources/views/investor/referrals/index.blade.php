@extends('layouts.app')

@section('content')
<div class="container-fluid px-4 py-6">
    <h1 class="text-3xl font-bold mb-6">My Referral Program</h1>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-gray-600 text-sm">Your Referral Code</p>
            <div class="flex items-center gap-2 mt-2">
                <p class="text-2xl font-bold text-blue-600" id="referralCode">{{ auth()->user()->referral_code }}</p>
                <button onclick="copyToClipboard()" class="text-blue-600 hover:underline">Copy</button>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-gray-600 text-sm">People Referred</p>
            <p class="text-3xl font-bold text-green-600">{{ auth()->user()->referral_count ?? 0 }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-gray-600 text-sm">Total Bonus Earned</p>
            <p class="text-3xl font-bold text-purple-600">${{ number_format(auth()->user()->referral_bonus ?? 0, 2) }}</p>
        </div>
    </div>

    <div class="bg-blue-50 p-6 rounded-lg mb-6">
        <h3 class="text-lg font-bold mb-2">How it Works</h3>
        <ul class="text-gray-700 space-y-2">
            <li>✓ Share your referral code with friends</li>
            <li>✓ When they sign up and invest, you earn a bonus</li>
            <li>✓ Earn {{ config('app.referral_bonus_percentage', 5) }}% from each referred person's first investment</li>
            <li>✓ Unlimited earning potential!</li>
        </ul>
    </div>

    <h2 class="text-2xl font-bold mb-4">People You've Referred</h2>

    @if($referrals->count())
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="px-6 py-3 text-left">Name</th>
                        <th class="px-6 py-3 text-left">Email</th>
                        <th class="px-6 py-3 text-left">Date Joined</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">Bonus Earned</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($referrals as $referral)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-3 font-semibold">{{ $referral->referred->full_name }}</td>
                            <td class="px-6 py-3">{{ $referral->referred->email }}</td>
                            <td class="px-6 py-3 text-sm">{{ $referral->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-3">
                                <span class="px-3 py-1 rounded text-sm font-semibold {{ $referral->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                    {{ ucfirst($referral->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 font-bold text-green-600">${{ number_format($referral->bonus_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $referrals->links() }}
        </div>
    @else
        <div class="bg-white rounded-lg shadow p-6 text-center text-gray-500">
            <p>You haven't referred anyone yet.</p>
            <p class="text-sm mt-2">Share your code above to start earning!</p>
        </div>
    @endif
</div>

<script>
function copyToClipboard() {
    const code = document.getElementById('referralCode').textContent;
    navigator.clipboard.writeText(code).then(() => {
        alert('Referral code copied to clipboard!');
    });
}
</script>
@endsection