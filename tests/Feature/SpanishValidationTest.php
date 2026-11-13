<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpanishValidationTest extends TestCase
{
    public function test_login_validation_messages_are_spanish(): void
    {
        $response = $this->from('/login')->post('/login', []);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email', 'password']);

        $errors = session('errors');

        $this->assertSame('El campo correo es obligatorio.', $errors->first('email'));
        $this->assertSame('El campo contraseña es obligatorio.', $errors->first('password'));
    }
}
