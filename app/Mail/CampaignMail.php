<?php

namespace App\Mail;

use App\Models\Campaign;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class CampaignMail extends Mailable
{
    public function __construct(public Campaign $campaign, public ?string $unsubscribeUrl = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->campaign->from_email, $this->campaign->from_name),
            subject: $this->campaign->subject,
        );
    }

    public function headers(): Headers
    {
        if ($this->unsubscribeUrl === null || $this->unsubscribeUrl === '') {
            return new Headers;
        }

        return new Headers(text: ['List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>']);
    }

    public function content(): Content
    {
        $html = (string) $this->campaign->body;

        if ($this->unsubscribeUrl !== null && $this->unsubscribeUrl !== '') {
            $html .= '<hr><p style="font-size:12px;color:#555">Recibes este correo porque aceptaste comunicaciones comerciales. '
                .'<a href="'.htmlspecialchars($this->unsubscribeUrl, ENT_QUOTES, 'UTF-8').'">Cancelar suscripción</a>.</p>';
        }

        return new Content(htmlString: $html);
    }
}
