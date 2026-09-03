<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Јавна регистрација нема. Сметките ги отвора канцеларијата.
 *
 * Овој фајл порано ја тестираше спротивното (дека секој може да се регистрира,
 * како што доаѓа Breeze). Останува како тест за да не се врати таа рута
 * незабележано — на пример при некоја идна надградба на Breeze.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_screen_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_no_route_is_named_register(): void
    {
        $this->assertFalse(
            Route::has('register'),
            'Рутата „register" повторно постои — јавната регистрација е отворена.'
        );
    }

    // Дека најавата и понатаму работи го покрива AuthenticationTest — и
    // екранот, и успешната најава, и погрешната лозинка.
}
