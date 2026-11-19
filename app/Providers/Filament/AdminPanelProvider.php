<?php

namespace App\Providers\Filament;

use Afsakar\FilamentOtpLogin\FilamentOtpLoginPlugin;
use App\Filament\Auth\Login;
use App\Filament\Pages\Wochenansicht;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->plugin(
                FilamentOtpLoginPlugin::make()
                    ->passwordless()
                    ->otpCode(length: 6, expiresIn: 300)
                    ->identifierFormField('email', 'E-Mail-Adresse', 'email')
                    ->loginPage(Login::class)
            )
            ->brandName('Wochenplan')
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/logo.svg'))
            ->colors([
                'primary' => Color::hex('#FF0011'),
                'gray' => Color::hex('#58616A'),
            ])
            // Mehr Breite für die Wochenspalten als mit Seitenleiste.
            ->topNavigation()
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(
                    '<link rel="stylesheet" href="'.e(asset('css/wochenplan.css')).'?v='.@filemtime(public_path('css/wochenplan.css')).'">'
                ),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Wochenansicht::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
