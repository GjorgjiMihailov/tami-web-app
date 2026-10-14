<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_page_is_public_and_names_google_drive(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Политика за приватност')
            ->assertSee('Google Drive');
    }
}
