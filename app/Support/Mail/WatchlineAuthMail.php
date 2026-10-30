<?php

namespace App\Support\Mail;

use Illuminate\Notifications\Messages\MailMessage;

final class WatchlineAuthMail
{
    public static function reset(object $notifiable, string $token): MailMessage
    {
        $url = route('front.auth.password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $mail = (new MailMessage)
            ->subject(__('Reset Password Notification'))
            ->line(__('You are receiving this email because we received a password reset request for your account.'))
            ->action(__('Reset Password'), $url)
            ->line(__('This password reset link will expire in :count minutes.', ['count' => $minutes]))
            ->line(__('If you did not request a password reset, no further action is required.'));

        return StoreMailBrand::usesSharedDesign()
            ? $mail->subject('Nova lozinka za vaš '.StoreMailBrand::name().' račun')->view('emails.watchline.auth', [
                'heading' => 'Postavite novu lozinku.',
                'intro' => 'Zaprimili smo zahtjev za promjenu lozinke vašeg '.StoreMailBrand::name().' računa. Novu lozinku postavite putem poveznice u nastavku.',
                'actionText' => 'Postavite novu lozinku', 'actionUrl' => $url,
                'expiryMinutes' => $minutes, 'isVerification' => false,
            ]) : $mail;
    }

    public static function verify(object $notifiable, string $url): MailMessage
    {
        $mail = (new MailMessage)->subject(__('Verify Email Address'))
            ->line(__('Please click the button below to verify your email address.'))
            ->action(__('Verify Email Address'), $url)
            ->line(__('If you did not create an account, no further action is required.'));

        return StoreMailBrand::usesSharedDesign()
            ? $mail->subject('Potvrdite e-mail adresu · '.StoreMailBrand::name())->view('emails.watchline.auth', [
                'heading' => 'Potvrdite svoju e-mail adresu.',
                'intro' => 'Još samo jedan korak do vašeg '.StoreMailBrand::name().' računa. Potvrdite svoju e-mail adresu putem poveznice u nastavku.',
                'actionText' => 'Potvrdite e-mail adresu', 'actionUrl' => $url,
                'expiryMinutes' => (int) config('auth.verification.expire', 60), 'isVerification' => true,
            ]) : $mail;
    }
}
