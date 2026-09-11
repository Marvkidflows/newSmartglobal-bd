<footer style="background: var(--primary-dark); color: white; padding: 4rem 2rem 2rem; margin-top: 4rem;">
    <div style="max-width: 1400px; margin: 0 auto;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 3rem; margin-bottom: 2rem;">
            <div>
                <h3 class="logo" style="color: white; margin-bottom: 1.5rem;">Smart<span>System</span></h3>
                <p style="color: rgba(255, 255, 255, 0.8); max-width: 300px; line-height: 1.8;">
                    Professional investment platform delivering consistent returns through advanced portfolio management.
                </p>
            </div>
            <div>
                <h4 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--accent);">Quick Links</h4>
                <ul style="list-style: none;">
                    <li style="margin-bottom: 0.75rem;"><a href="{{ route('about') }}" style="color: rgba(255, 255, 255, 0.8); text-decoration: none; transition: color 0.3s;">About Us</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="{{ route('plans') }}" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">Investment Plans</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="{{ route('how-it-works') }}" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">How It Works</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="{{ route('faq') }}" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h4 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--accent);">Legal</h4>
                <ul style="list-style: none;">
                    <li style="margin-bottom: 0.75rem;"><a href="#" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">Terms of Service</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="#" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">Privacy Policy</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="#" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">Risk Disclosure</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="#" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">Cookie Policy</a></li>
                </ul>
            </div>
            <div>
                <h4 style="font-weight: 700; margin-bottom: 1.5rem; color: var(--accent);">Contact</h4>
                <ul style="list-style: none;">
                    <li style="margin-bottom: 0.75rem;"><a href="{{ route('contact') }}" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">Contact Us</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="mailto:support@smartsystem.com" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">support@smartsystem.com</a></li>
                    <li style="margin-bottom: 0.75rem;"><a href="tel:+15551234567" style="color: rgba(255, 255, 255, 0.8); text-decoration: none;">+1 (555) 123-4567</a></li>
                </ul>
            </div>
        </div>
        <div style="padding-top: 2rem; border-top: 1px solid rgba(255, 255, 255, 0.1); text-align: center; color: rgba(255, 255, 255, 0.6);">
            <p>© {{ date('Y') }} Smart System Investment. All rights reserved. | Regulated and Secure</p>
        </div>
    </div>
</footer>