<?php

namespace App\Mail;

use App\Models\Jornada;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso al conductor de que le han asignado una jornada (se envía una vez por jornada). */
class JornadaAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Jornada $jornada) {}

    private function ownerName(): string
    {
        $owner = $this->jornada->owner;

        return trim($owner->first_name.' '.$owner->last_name);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->ownerName()} te ha asignado la jornada del {$this->jornada->date->format('d/m/Y')}",
            replyTo: $this->jornada->owner->email ? [$this->jornada->owner->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.jornada-assigned', with: [
            'ownerName' => $this->ownerName(),
            'label' => $this->jornada->label,
            'date' => $this->jornada->date->format('d/m/Y'),
        ]);
    }
}
