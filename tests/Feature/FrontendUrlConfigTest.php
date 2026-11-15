<?php
// LOCATION: tests/Feature/FrontendUrlConfigTest.php   (NEW FILE)   WRITTEN, NOT EXECUTED in the build environment (no PHP runtime there).
//
// Regression test for a confirmed defect: config('app.frontend_url') was defined nowhere (and the FRONTEND_URL variable was never read), so the
// password-reset e-mail contained a link with no domain at all. Links in e-mails must point at the public WEBSITE (frontend), not the API host.

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Tests\TestCase;

class FrontendUrlConfigTest extends TestCase
{
    public function test_a_frontend_url_is_configured_and_is_an_absolute_url(): void
    {
        $url = (string) config('app.frontend_url');

        $this->assertNotSame('', $url, 'app.frontend_url must be defined');
        $this->assertNotFalse(filter_var($url, FILTER_VALIDATE_URL), 'app.frontend_url must be an absolute URL');
        $this->assertMatchesRegularExpression('#^https?://#', $url);
    }

    public function test_the_password_reset_link_is_built_on_the_frontend_domain(): void
    {
        config(['app.frontend_url' => 'https://site.example.test/']);   // trailing slash on purpose: it must not double up

        $user = new User();
        $user->email = 'person@example.test';
        $user->name = 'Person';

        $mail = (new ResetPasswordNotification('tok123'))->toMail($user);

        $this->assertStringStartsWith('https://site.example.test/reset-password?token=tok123&email=', $mail->actionUrl);
        $this->assertStringNotContainsString('//reset-password', $mail->actionUrl);
    }

    public function test_the_reset_link_never_falls_back_to_a_bare_path(): void
    {
        $user = new User();
        $user->email = 'person@example.test';

        $mail = (new ResetPasswordNotification('tok123'))->toMail($user);

        $this->assertStringStartsWith('http', $mail->actionUrl, 'a link without a domain cannot be opened from an e-mail');
    }
}
