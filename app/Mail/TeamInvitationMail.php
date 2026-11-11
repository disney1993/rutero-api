<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitación al equipo de un propietario enviada por correo. No lleva
 * código: quien la recibe la acepta en Rutero (Inicio) al entrar o
 * registrarse con este mismo email.
 */
class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $owner, public string $email) {}

    public function envelope(): Envelope
    {
        $name = trim($this->owner->first_name.' '.$this->owner->last_name);

        return new Envelope(
            subject: "{$name} te invita a su equipo en Rutero",
            replyTo: $this->owner->email ? [$this->owner->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.team-invitation', with: [
            'ownerName' => trim($this->owner->first_name.' '.$this->owner->last_name),
            'email' => $this->email,
            'downloadUrl' => config('rutero.app_links.download_url'),
        ]);
    }
}
