<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_404_page_renders_in_macedonian(): void
    {
        $this->get('/this-route-does-not-exist')
            ->assertStatus(404)
            ->assertSee('Бараната страница не е пронајдена.');
    }

    public function test_403_page_renders_in_macedonian(): void
    {
        $response = $this->withoutExceptionHandling()->get('/');
        // 403 is exercised indirectly by policy tests elsewhere; here we
        // confirm the view itself renders correctly when invoked directly.
        $view = view('errors.403');

        $this->assertStringContainsString('Немате дозвола за пристап до оваа страница.', $view->render());
    }
}
