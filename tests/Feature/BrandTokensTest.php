<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandTokensTest extends TestCase
{
    public function test_shared_css_defines_the_graphite_coral_tokens(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        foreach ([
            '--paper' => '#F6F4EF',
            '--ink' => '#20252A',
            '--ink-muted' => '#5F6863',
            '--accent' => '#B23A31',
            '--accent-bright' => '#D14B3F',
            '--accent-soft' => '#F7E4E0',
            '--blue' => '#2B4C7E',
            '--focus' => '#2B4C7E',
        ] as $token => $value) {
            $this->assertStringContainsString($token.': '.$value.';', $css);
        }
    }
}
