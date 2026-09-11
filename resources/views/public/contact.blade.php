@extends('layouts.app')

@section('title', 'Contact Us - Smart System')

@section('content')
<section class="section">
    <div class="section-header">
        <h2 class="section-title">Contact Us</h2>
        <p class="section-subtitle">Get in touch with our team</p>
    </div>
    
    <div style="max-width: 600px; margin: 0 auto;">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <form method="POST" action="{{ route('contact.submit') }}" style="background: white; padding: 2.5rem; border-radius: 16px; box-shadow: 0 4px 20px var(--shadow);">
            @csrf
            
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Subject</label>
                <input type="text" name="subject" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Message</label>
                <textarea name="message" class="form-input" rows="6" required></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">Send Message</button>
        </form>

        <div style="margin-top: 3rem; text-align: center;">
            <p style="color: var(--text-secondary); margin-bottom: 1rem;">Or reach us directly:</p>
            <p style="margin-bottom: 0.5rem;"><strong>Email:</strong> support@smartsystem.com</p>
            <p style="margin-bottom: 0.5rem;"><strong>Phone:</strong> +1 (555) 123-4567</p>
            <p><strong>Hours:</strong> Monday - Friday, 9am - 6pm EST</p>
        </div>
    </div>
</section>
@endsection