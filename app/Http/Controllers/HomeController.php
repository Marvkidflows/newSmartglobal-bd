<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        return view('public.home');
    }

    public function about()
    {
        return view('public.about');
    }

    public function plans()
    {
        $plans = [
            [
                'name' => 'Starter',
                'min_investment' => 1000,
                'daily_return' => '1.5% - 2.0%',
                'features' => [
                    'Basic trading algorithms',
                    'Email support',
                    'Weekly reports',
                    'Standard withdrawal (3-5 days)',
                ]
            ],
            [
                'name' => 'Professional',
                'min_investment' => 5000,
                'daily_return' => '2.0% - 2.5%',
                'popular' => true,
                'features' => [
                    'Advanced trading algorithms',
                    'Priority support',
                    'Daily reports',
                    'Fast withdrawal (24-48 hours)',
                    'Premium tasks',
                ]
            ],
            [
                'name' => 'Elite',
                'min_investment' => 15000,
                'daily_return' => '2.5% - 3.0%',
                'features' => [
                    'Elite algorithms + AI',
                    'Dedicated account manager',
                    'Real-time analytics',
                    'Instant withdrawal',
                    'VIP tasks',
                    'Portfolio optimization',
                ]
            ],
            [
                'name' => 'Institutional',
                'min_investment' => 50000,
                'daily_return' => '3.0% - 3.8%',
                'features' => [
                    'Custom algorithm deployment',
                    '24/7 concierge support',
                    'Custom reporting',
                    'Priority withdrawal',
                    'Exclusive opportunities',
                    'Hedge fund strategies',
                ]
            ],
        ];

        return view('public.plans', compact('plans'));
    }

    public function howItWorks()
    {
        return view('public.how-it-works');
    }

    public function faq()
    {
        $faqs = [
            [
                'question' => 'What is Smart System Investment?',
                'answer' => 'Smart System Investment is a professional investment platform that helps individuals grow their wealth through diversified investment strategies managed by expert portfolio managers.'
            ],
            [
                'question' => 'How do I get started?',
                'answer' => 'Simply register for an account, choose an investment plan that suits your goals, make your initial deposit, and start earning returns immediately.'
            ],
            // Add more FAQs...
        ];

        return view('public.faq', compact('faqs'));
    }

    public function contact()
    {
        return view('public.contact');
    }

    public function contactSubmit(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'message' => 'required|string|min:10',
        ]);

        // Send email to admin
        // Mail::to('support@smartsystem.com')->send(new ContactMessage($validated));

        return back()->with('success', 'Thank you for your message. We will get back to you soon!');
    }
}