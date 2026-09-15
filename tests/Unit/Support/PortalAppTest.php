<?php

namespace Tests\Unit\Support;

use App\Support\PortalApp;
use Tests\TestCase;

class PortalAppTest extends TestCase
{
    public function test_domain_comes_from_config(): void
    {
        config(['apps.domains.prodazba' => 'prodazba.example']);

        $this->assertSame('prodazba.example', PortalApp::PRODAZBA->domain());
    }

    public function test_host_is_matched_back_to_its_app(): void
    {
        $this->assertSame(PortalApp::FINANSII, PortalApp::fromHost(PortalApp::FINANSII->domain()));
    }

    public function test_an_unknown_host_matches_nothing(): void
    {
        $this->assertNull(PortalApp::fromHost('tuѓ.example'));
    }

    public function test_work_apps_are_the_three_without_the_portal(): void
    {
        $this->assertSame(
            [PortalApp::PRODAZBA, PortalApp::FINANSII, PortalApp::PLATA],
            PortalApp::workApps()
        );
    }

    public function test_the_portal_has_no_permission_column(): void
    {
        $this->assertNull(PortalApp::PORTAL->userColumn());
        $this->assertSame('app_prodazba', PortalApp::PRODAZBA->userColumn());
        $this->assertSame('app_finansii', PortalApp::FINANSII->userColumn());
        $this->assertSame('app_plata', PortalApp::PLATA->userColumn());
    }

    public function test_every_app_has_a_macedonian_label(): void
    {
        $this->assertSame('Продажба', PortalApp::PRODAZBA->label());
        $this->assertSame('Финансии', PortalApp::FINANSII->label());
        $this->assertSame('Плата', PortalApp::PLATA->label());
        $this->assertSame('Портал', PortalApp::PORTAL->label());
    }
}
