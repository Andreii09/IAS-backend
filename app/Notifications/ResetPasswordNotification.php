<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

class ResetPasswordNotification extends ResetPassword
{
    /** Point reset links at the React SPA rather than a Laravel route. */
    protected function resetUrl($notifiable): string
    {
        $frontend = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return $frontend
            .'/reset-password/'.$this->token
            .'?email='.urlencode($notifiable->getEmailForPasswordReset());
    }
}
