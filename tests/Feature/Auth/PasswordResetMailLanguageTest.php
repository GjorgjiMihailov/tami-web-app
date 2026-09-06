<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пораката за нова лозинка ја составува самиот Laravel, не апликацијата —
 * и телото и рамката минуваат низ `__()`. Кога `lang/mk.json` беше додаден
 * заради поканата, рамката стана македонска, а телото остана англиско.
 * Овој тест го држи целото писмо на еден јазик.
 */
class PasswordResetMailLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rendered_email_is_macedonian_throughout(): void
    {
        $user = User::factory()->create();

        $html = (string) (new ResetPassword('test-token'))->toMail($user)->render();

        foreach ([
            'Hello!',
            'Regards,',
            'trouble clicking',
            'All rights reserved',
            'You are receiving this email',
            'Reset Password',
            'will expire in',
            'no further action is required',
        ] as $english) {
            $this->assertStringNotContainsString($english, $html);
        }

        $this->assertStringContainsString('Постави нова лозинка', $html);
        $this->assertStringContainsString('Линкот важи 60 минути', $html);
        $this->assertStringContainsString('Поздрав', $html);
    }
}
