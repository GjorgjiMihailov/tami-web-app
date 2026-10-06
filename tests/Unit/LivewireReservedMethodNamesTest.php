<?php

namespace Tests\Unit;

use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Livewire во прелистувачот има вградени функции на `$wire` со овие имиња.
 * Ако PHP-акција се вика исто, `wire:submit="upload"` го вика вградениот JS
 * (со празен аргумент) и барањето НИКОГАШ не стигнува до серверот: копчето
 * „не прави ништо“, а автоматските тестови (кои ја викаат акцијата директно)
 * поминуваат. Така измина прикачувањето на изводи, 743 обрасци и документи.
 *
 * Листата е копија на `aliases` од vendor/livewire/livewire/dist/livewire.js.
 */
class LivewireReservedMethodNamesTest extends TestCase
{
    private const RESERVED = [
        'on', 'el', 'id', 'js', 'get', 'set', 'call', 'hook', 'commit', 'watch', 'entangle',
        'dispatch', 'dispatchTo', 'dispatchSelf',
        'upload', 'uploadMultiple', 'removeUpload', 'cancelUpload',
    ];

    /** @return list<class-string<Component>> */
    private function components(): array
    {
        $root = app_path('Livewire');
        $classes = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(['/', '\\'], '\\', substr($file->getPathname(), strlen(app_path()) + 1, -4));
            $class = 'App\\'.$relative;
            $reflection = new ReflectionClass($class);

            if ($reflection->isInstantiable() && $reflection->isSubclassOf(Component::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function test_no_livewire_component_declares_an_action_named_like_a_livewire_browser_helper(): void
    {
        $offenders = [];

        foreach ($this->components() as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $declaredByApp = str_starts_with($method->getDeclaringClass()->getName(), 'App\\');

                if ($declaredByApp && in_array($method->getName(), self::RESERVED, true)) {
                    $offenders[] = $class.'::'.$method->getName();
                }
            }
        }

        $this->assertSame([], $offenders, 'Овие акции се засенети од Livewire во прелистувачот и не работат: '.implode(', ', $offenders));
    }

    public function test_the_scan_actually_finds_the_components(): void
    {
        $this->assertGreaterThan(30, count($this->components()));
    }
}
