<?php
// LOCATION: app/Http/Controllers/Investor/InvestorEmailController.php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\SentEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvestorEmailController extends Controller
{
    // GET /investor-investment/emails
    public function index(Request $request)
    {
        $investor = Auth::user();

        $emails = SentEmail::where('investor_id', $investor->id)
            ->where('status', 'sent') // don't show failed sends to the investor
            ->latest()
            ->get()
            ->map(fn($e) => [
                'id'         => $e->id,
                'subject'    => $e->subject,
                'sender'     => 'Smart System Investment',
                'sent_at'    => $e->sent_at?->toDateTimeString(),
                'time_ago'   => $e->sent_at?->diffForHumans(),
                'is_read'    => $e->read_by_investor,
                'has_attachment' => !is_null($e->attachment_path),
            ]);

        $unreadCount = SentEmail::where('investor_id', $investor->id)
            ->where('status', 'sent')
            ->where('read_by_investor', false)
            ->count();

        return response()->json([
            'emails'       => $emails,
            'unread_count' => $unreadCount,
        ]);
    }

    // GET /investor-investment/emails/{sentEmail}
    public function show(Request $request, SentEmail $sentEmail)
    {
        $investor = Auth::user();

        if ($sentEmail->investor_id !== $investor->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (!$sentEmail->read_by_investor) {
            $sentEmail->update(['read_by_investor' => true, 'read_at' => now()]);
        }

        return response()->json([
            'email' => [
                'id'         => $sentEmail->id,
                'subject'    => $sentEmail->subject,
                'body_html'  => $sentEmail->body_html,
                'sender'     => 'Smart System Investment',
                'sent_at'    => $sentEmail->sent_at?->toDateTimeString(),
                'has_attachment' => !is_null($sentEmail->attachment_path),
            ],
        ]);
    }
}