<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SecurityNoticeMail extends Mailable
{
    public function __construct(public string $notice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Aviso de seguridad de tu cuenta');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.security-notice');
    }
}
