<?php

namespace Italia\SPIDAuth\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

use Orchestra\Testbench\TestCase;

class MiddlewareTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return ['Italia\SPIDAuth\ServiceProvider'];
    }

    public function testUnauthenticated()
    {
        $loginURL = URL::route('spid-auth_login');
        Route::get('/', function () {
            return 'home';
        })->middleware('spid.auth');
        $response = $this->get('/');
        $response->assertRedirect($loginURL);
        $response->assertSessionHas('url.intended', URL::to('/'));
    }
    
    public function testAuthenticated()
    {
        $loginURL = URL::route('spid-auth_login');
        Route::get('/', function () {
            return 'home';
        })->middleware('spid.auth');
        $response = $this->withSession(['spid_sessionIndex' => 'sessionIndex'])->get('/');
        $response->assertSuccessful();
    }
}
