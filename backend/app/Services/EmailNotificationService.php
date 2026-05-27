<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    public function configureMailer(): void
    {
        $mailer = (string) SystemSetting::get('mail_mailer', config('mail.default', 'log'));
        $fromAddress = (string) SystemSetting::get('smtp_from_address', config('mail.from.address'));
        $fromName = (string) SystemSetting::get('smtp_from_name', config('mail.from.name', 'OnLiFi'));

        Config::set('mail.default', $mailer);
        Config::set('mail.from.address', $fromAddress);
        Config::set('mail.from.name', $fromName);

        if ($mailer === 'smtp') {
            Config::set('mail.mailers.smtp.host', (string) SystemSetting::get('smtp_host', config('mail.mailers.smtp.host')));
            Config::set('mail.mailers.smtp.port', (int) SystemSetting::get('smtp_port', config('mail.mailers.smtp.port', 587)));
            Config::set('mail.mailers.smtp.username', (string) SystemSetting::get('smtp_username', config('mail.mailers.smtp.username')));
            Config::set('mail.mailers.smtp.password', (string) SystemSetting::get('smtp_password', config('mail.mailers.smtp.password')));
            Config::set('mail.mailers.smtp.scheme', (string) SystemSetting::get('smtp_encryption', config('mail.mailers.smtp.scheme', 'tls')) ?: null);
        }
    }

    public function sendSignupReceived(Tenant $tenant): void
    {
        if (!SystemSetting::get('notify_signup_email', true)) {
            return;
        }

        $this->sendToTenantUsers(
            $tenant,
            'Your OnLiFi signup was received',
            "Hello {$tenant->name},\n\nYour OnLiFi signup was received and is waiting for administrator approval.\n\nWe will notify you once your account is activated."
        );
    }

    public function sendActivationConfirmation(Tenant $tenant): void
    {
        if (!SystemSetting::get('notify_activation_email', true)) {
            return;
        }

        $this->sendToTenantUsers(
            $tenant,
            'Your OnLiFi account is active',
            "Hello {$tenant->name},\n\nYour OnLiFi tenant account has been approved and activated.\n\nYou can now sign in, create vouchers, configure your captive page, and provision your MikroTik router."
        );
    }

    public function sendPasswordResetNotice(Tenant $tenant): void
    {
        if (!SystemSetting::get('notify_password_reset_email', true)) {
            return;
        }

        $this->sendToTenantUsers(
            $tenant,
            'Your OnLiFi password was reset',
            "Hello {$tenant->name},\n\nAn administrator reset your OnLiFi account password. If you did not request this, contact support immediately."
        );
    }

    public function sendForgotPasswordLink(TenantUser $user, string $token): void
    {
        if (!SystemSetting::get('notify_password_reset_email', true) || !$user->email) {
            return;
        }

        $this->configureMailer();
        $dashboardUrl = rtrim((string) SystemSetting::get('dashboard_url', config('app.frontend_url')), '/');
        $resetUrl = $dashboardUrl . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);
        $body = "Hello {$user->name},\n\nWe received a request to reset your OnLiFi password.\n\nOpen this link to set a new password:\n{$resetUrl}\n\nThis link expires in 60 minutes. If you did not request it, ignore this email.";

        try {
            Mail::raw($body, function ($message) use ($user) {
                $message->to($user->email)->subject('Reset your OnLiFi password');
            });
        } catch (\Throwable $e) {
            Log::warning('Forgot password email failed', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendAnnouncement(Announcement $announcement): void
    {
        if (!SystemSetting::get('notify_announcement_email', false)) {
            return;
        }

        $query = Tenant::where('status', 'approved')->where('is_active', true);

        if ($announcement->target === 'specific' && is_array($announcement->tenant_ids)) {
            $query->whereIn('id', $announcement->tenant_ids);
        }

        foreach ($query->with('users')->cursor() as $tenant) {
            $this->sendToTenantUsers($tenant, $announcement->title, $announcement->content);
        }
    }

    private function sendToTenantUsers(Tenant $tenant, string $subject, string $body): void
    {
        $this->configureMailer();
        $tenant->loadMissing('users');

        foreach ($tenant->users as $user) {
            if (!$user->email) {
                continue;
            }

            try {
                Mail::raw($body, function ($message) use ($user, $subject) {
                    $message->to($user->email)->subject($subject);
                });
            } catch (\Throwable $e) {
                Log::warning('Email notification failed', [
                    'email' => $user->email,
                    'subject' => $subject,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
