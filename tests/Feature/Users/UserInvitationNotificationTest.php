<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Tests\TestCase;

/**
 * Ова е единствената порака што апликацијата ја праќа надвор од
 * канцеларијата. Телото на пораката е на македонски, но Laravel ја рендира
 * внатре во стандардниот markdown шаблон на нотификациите
 * (`notifications::email`), чии преостанати низи (поздрав, потпис,
 * подзапис за копчето, фуснота за авторски права) минуваат low-level низ
 * @lang()/__() и без `lang/mk.json` остануваат на англиски.
 */
class UserInvitationNotificationTest extends TestCase
{
    public function test_the_rendered_email_has_no_english_chrome(): void
    {
        $user = User::factory()->make(['name' => 'Марија Петровска']);

        $html = (string) (new UserInvitationNotification('https://portal.financebuddy.mk/invitation/test-token'))
            ->toMail($user)
            ->render();

        // Англиските низи од стандардниот шаблон на нотификациите — сите
        // мора да имаат превод во lang/mk.json.
        $this->assertStringNotContainsString('Hello!', $html);
        $this->assertStringNotContainsString('Whoops!', $html);
        $this->assertStringNotContainsString('Regards,', $html);
        $this->assertStringNotContainsString('trouble clicking', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);

        // Македонскиот текст мора да остане присутен.
        $this->assertStringContainsString('Постави лозинка', $html);
        $this->assertStringContainsString('Поздрав, FinanceBuddy', $html);
        $this->assertStringContainsString('Сите права задржани', $html);
    }
}
