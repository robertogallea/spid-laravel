<?php

namespace Italia\SPIDAuth\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\URL;

use Italia\SPIDAuth\Exceptions\SPIDLogoutException;
use Italia\SPIDAuth\Exceptions\SPIDMetadataException;
use Italia\SPIDAuth\Exceptions\SPIDLoginException;
use Orchestra\Testbench\TestCase;
use Mockery as m;
use OneLogin\Saml2\Error as SAMLError;
use OneLogin\Saml2\Constants as SAMLConstants;

use DOMDocument;

use Italia\SPIDAuth\SPIDAuth;
use Italia\SPIDAuth\SPIDUser;
use Italia\SPIDAuth\Events\LoginEvent;
use Italia\SPIDAuth\Events\LogoutEvent;

class SPIDAuthTest extends TestCase
{
    protected $SPIDAuth;
    protected $loginURL;
    protected $acsURL;
    protected $logoutURL;
    protected $afterLoginURL;
    protected $afterLogoutURL;
    protected $loginView;
    protected $metadataURL;
    protected $providersURL;

    protected function setUp()
    {
        parent::setUp();
        
        $this->loginURL = URL::route('spid-auth_login');
        $this->doLoginURL = URL::route('spid-auth_do-login');
        $this->acsURL = URL::route('spid-auth_acs');
        $this->afterLoginURL = $this->app['config']->get('spid-auth.after_login_url');
        $this->afterLogoutURL = $this->app['config']->get('spid-auth.after_logout_url');
        $this->logoutURL = URL::route('spid-auth_logout');
        $this->loginView = $this->app['config']->get('spid-auth.login_view');
        $this->metadataURL = URL::route('spid-auth_metadata');
        $this->providersURL = URL::route('spid-auth_providers');
    }
    
    protected function tearDown()
    {
        m::close();
    }
    
    protected function getPackageProviders($app)
    {
        return ['Italia\SPIDAuth\ServiceProvider'];
    }
    
    protected function setSPIDAuthMock(bool $withErrors = false, bool $isAuthenticated = true, bool $withInvalidBinding = false)
    {
        $testRedirectURL = $this->app['config']->get('spid-idps.test.singleSignOnService.url');
        $this->SPIDAuth = m::mock(SPIDAuth::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $SAMLAuth = m::mock('\OneLogin\Saml2\Auth');
        $SAMLAuth->shouldReceive('login')->andReturn(
            Response::redirectTo($testRedirectURL)
        );
        if (!$withInvalidBinding) {
            $SAMLAuth->shouldReceive('processResponse')->andReturn(true);
            $SAMLAuth->shouldReceive('processSLO')->andReturn(true);
        } else {
            $SAMLAuth->shouldReceive('processResponse')->andThrow(
                new SAMLError(
                    'SAML Response not found, Only supported HTTP_POST Binding',
                    SAMLError::SAML_RESPONSE_NOT_FOUND
                )
            );
            $SAMLAuth->shouldReceive('processSLO')->andThrow(
                new SAMLError(
                    'SAML LogoutRequest/LogoutResponse not found. Only supported HTTP_REDIRECT Binding',
                    SAMLError::SAML_LOGOUTMESSAGE_NOT_FOUND
                )
            );
        }
        $SAMLAuth->shouldReceive('getLastAssertionId')->andReturn('assertionId');
        $SAMLAuth->shouldReceive('getLastAssertionNotOnOrAfter')->andReturn(time() + 300);
        $SAMLAuth->shouldReceive('getLastErrorReason')->andReturn('errorReason');
        $SAMLAuth->shouldReceive('isAuthenticated')->andReturn($isAuthenticated);
        $SAMLAuth->shouldReceive('getAttributes')->andReturn([
            'spidCode' => ['TEST0123456789'],
            'name' => ['Nome'],
            'familyName' => ['Cognome'],
            'fiscalNumber' => ['FSCLNB17A01H501X']
        ]);
        $SAMLAuth->shouldReceive('getLastResponseXML')->andReturn(
            '<?xml version="1.0" encoding="UTF-8"?>
            <saml2p:Response xmlns:saml2p="urn:oasis:names:tc:SAML:2.0:protocol">
               <saml2:Issuer xmlns:saml2="urn:oasis:names:tc:SAML:2.0:assertion">spid-testenv</saml2:Issuer>
            </saml2p:Response>'
        );
        $SAMLAuth->shouldReceive('getSessionIndex')->andReturn('sessionIndex');
        $SAMLAuth->shouldReceive('getNameId')->andReturn('nameId');
        if (!$withErrors) {
            $SAMLAuth->shouldReceive('logout')->with(URL::to($this->afterLogoutURL), [], 'nameId', 'sessionIndex', false, SAMLConstants::NAMEID_TRANSIENT, 'spid-testenv')->andReturn(
                Response::redirectTo($this->logoutURL)
            );
            $SAMLAuth->shouldReceive('getErrors')->andReturn(false);
            $SAMLAuth->shouldReceive('getSPMetadata')->andReturn();
        } else {
            $SAMLAuth->shouldReceive('logout')->with(URL::to($this->afterLogoutURL), [], 'nameId', 'sessionIndex', false, SAMLConstants::NAMEID_TRANSIENT, 'spid-testenv')->andThrow(
                new SAMLError(
                    'The IdP does not support Single Log Out',
                    SAMLError::SAML_SINGLE_LOGOUT_NOT_SUPPORTED
                )
            );
            $SAMLAuth->shouldReceive('getSPMetadata')->andThrow(
                new SAMLError(
                    'Invalid metadata syntax',
                    SAMLError::SETTINGS_INVALID_SYNTAX
                )
            );
            $SAMLAuth->shouldReceive('getErrors')->andReturn(['error']);
        }
        $SAMLAuth->shouldReceive('getSettings')->andReturn($SAMLAuth);
        $SAMLAuth->shouldReceive('validateMetadata')->andReturn(['error']);
        $this->SPIDAuth->shouldReceive('getSAML')->andReturn($SAMLAuth);
        $this->app->instance('SPIDAuth', $this->SPIDAuth);
    }
    
    public function testLogin()
    {
        $response = $this->get($this->loginURL);
        $response->assertViewIs($this->loginView);
    }
    
    public function testLoginIfAuthenticated()
    {
        $response = $this->withSession(['spid_sessionIndex' => 'sessionIndex'])->get($this->loginURL);
        $response->assertRedirect($this->afterLoginURL);
    }
    
    public function testLoginIfAuthenticatedWithIntendedURL()
    {
        $response = $this->withSession(['spid_sessionIndex' => 'sessionIndex', 'url.intended' => 'intendedURL'])->get($this->loginURL);
        $response->assertRedirect('intendedURL');
    }
    
    
    public function testDoLoginIfAuthenticated()
    {
        $response = $this->withSession(['spid_sessionIndex' => 'sessionIndex'])->post($this->doLoginURL);
        $response->assertRedirect($this->afterLoginURL);
    }
    
    public function testDoLoginIfAuthenticatedWithIntendedURL()
    {
        $response = $this->withSession(['spid_sessionIndex' => 'sessionIndex', 'url.intended' => 'intendedURL'])->post($this->doLoginURL);
        $response->assertRedirect('intendedURL');
    }
    
    public function testDoLoginWithoutProvider()
    {
        $response = $this->post($this->doLoginURL);
        $response->assertStatus(400);
    }
    
    public function testDoLogin()
    {
        $this->setSPIDAuthMock();
        $response = $this->post($this->doLoginURL, ['provider' => 'test']);
        $response->assertSessionHas('spid_idp');
        $response->assertRedirect();
    }
    
    public function testAcs()
    {
        Event::fake();
        $this->setSPIDAuthMock();
        $response = $this->post($this->acsURL);
        $response->assertSessionHas('spid_idp_entity_name');
        $response->assertSessionHas('spid_sessionIndex');
        $response->assertSessionHas('spid_nameId');
        $response->assertSessionHas('spid_user');
        $response->assertRedirect($this->afterLoginURL);
        Event::assertDispatched(LoginEvent::class, function ($e) {
            $SPIDUser = $e->getSPIDUser();
            $isSPIDUserReturned = get_class($SPIDUser) == SPIDUser::class;
            $areSPIDUserFieldsSet =
                $SPIDUser->name == 'Nome' &&
                $SPIDUser->familyName == 'Cognome' &&
                $SPIDUser->fiscalNumber == 'FSCLNB17A01H501X' &&
                $SPIDUser->spidCode == 'TEST0123456789' &&
                $SPIDUser->nonExistent == null;
            $isIdpReturned = $e->getIdp() == 'Test IdP';
            return $isSPIDUserReturned && $areSPIDUserFieldsSet && $isIdpReturned;
        });
        $SPIDUser = $this->app->make('SPIDAuth')->getSPIDUser();
        $this->assertInstanceOf(SPIDUser::class, $SPIDUser);
    }
    
    public function testAcsWithIntendedURL()
    {
        $this->setSPIDAuthMock();
        $response = $this->withSession(['url.intended' => 'intendedURL'])->post($this->acsURL);
        $response->assertRedirect('intendedURL');
    }
    
    public function testAcsWithMalformedSAMLResponse()
    {
        $this->withoutExceptionHandling();
        $this->expectException(SPIDLoginException::class);
        $this->setSPIDAuthMock(true);
        $response = $this->post($this->acsURL);
        $response->assertStatus(500);
    }

    public function testAcsWithInvalidBinding()
    {
        $this->withoutExceptionHandling();
        $this->expectException(SPIDLoginException::class);
        $this->setSPIDAuthMock(false, true, true);
        $response = $this->post($this->acsURL);
        $response->assertStatus(500);
    }

    public function testAcsWithFailedSAMLAuthentication()
    {
        $this->setSPIDAuthMock(false, false);
        $response = $this->post($this->acsURL);
        $response->assertStatus(500);
    }
    
    public function testAcsWithReplayAttack()
    {
        $this->withoutExceptionHandling();
        $this->expectException(SPIDLoginException::class);
        $this->setSPIDAuthMock();
        $response = $this->post($this->acsURL);
        $response = $this->post($this->acsURL);
        $response->assertStatus(500);
    }
    
    public function testLogout()
    {
        $this->withoutExceptionHandling();
        $this->testAcs();
        $response = $this->withSession(['spid_idp' => 'test'])->get($this->logoutURL);
        $response->assertSessionMissing('spid_sessionIndex');
        $response->assertSessionMissing('spid_nameId');
        $response->assertRedirect($this->logoutURL);
    }
    
    public function testSLO()
    {
        Event::fake();
        $this->testLogout();
        $response = $this->get($this->logoutURL . '?SAMLResponse');
        $response->assertSessionMissing('spid_idp_entity_name');
        $response->assertSessionMissing('spid_idp');
        $response->assertRedirect($this->afterLogoutURL);
        Event::assertDispatched(LogoutEvent::class, function ($e) {
            $SPIDUser = $e->getSPIDUser();
            $isSPIDUserReturned = get_class($SPIDUser) == SPIDUser::class;
            $areSPIDUserFieldsSet =
                $SPIDUser->name == 'Nome' &&
                $SPIDUser->familyName == 'Cognome' &&
                $SPIDUser->fiscalNumber == 'FSCLNB17A01H501X' &&
                $SPIDUser->spidCode == 'TEST0123456789' &&
                $SPIDUser->nonExistent == null;
            $isIdpReturned = $e->getIdp() == 'Test IdP';
            return $isSPIDUserReturned && $areSPIDUserFieldsSet && $isIdpReturned;
        });
        $SPIDUser = $this->app->make('SPIDAuth')->getSPIDUser();
        $this->assertNull($SPIDUser);
    }
    
    public function testSLOWithInvalidBinding()
    {
      $this->setSPIDAuthMock(false, false, true);
      $this->withoutExceptionHandling();
      $this->expectException(SPIDLogoutException::class);
      $response = $this->withSession(['spid_idp_entity_name' => 'spid_idp_entity_name', 'spid_user' => new SPIDUser([])])->get($this->logoutURL . '?SAMLResponse');
      $response->assertStatus(500);
    }
    
    public function testSLOWithMalformedSAMLResponse()
    {
      $this->setSPIDAuthMock(true, false);
      $this->withoutExceptionHandling();
      $this->expectException(SPIDLogoutException::class);
      $response = $this->withSession(['spid_idp_entity_name' => 'spid_idp_entity_name', 'spid_user' => new SPIDUser([])])->get($this->logoutURL . '?SAMLResponse');
      $response->assertStatus(500);
    }
    
    public function testLogoutIfNotAuthenticated()
    {
        $response = $this->get($this->logoutURL);
        $response->assertRedirect($this->afterLogoutURL);
    }

    public function testLogoutWithErrors()
    {
        $this->withoutExceptionHandling();
        $this->expectException(SPIDLogoutException::class);
        $this->testAcs();
        $this->setSPIDAuthMock(true);
        $response = $this->withSession(['spid_idp' => 'test'])->get($this->logoutURL);
        $response->assertStatus(500);
    }
    
    public function testMetadata()
    {
        $response = $this->get($this->metadataURL);
        $response->assertStatus(200);
        $metadata = new DOMDocument;
        $metadata->loadXML($response->getContent());
        $this->assertTrue($metadata->schemaValidate('tests/xml-schemas/saml-schema-metadata-SPID-SP.xsd'));
    }
    
    public function testNotValidMetadata()
    {
        $this->withoutExceptionHandling();
        $this->expectException(SPIDMetadataException::class);
        $this->setSPIDAuthMock();
        $response = $this->get($this->metadataURL);
        $response->assertStatus(500);
    }

    public function testMalformedMetadata()
    {
        $this->withoutExceptionHandling();
        $this->expectException(SPIDMetadataException::class);
        $this->setSPIDAuthMock(true);
        $response = $this->get($this->metadataURL);
        $response->assertStatus(500);
    }
    
    public function testProvidersWithTestIdp()
    {
        $response = $this->get($this->providersURL);
        $response->assertJson(['spidProviders' => array_values($this->app['config']->get('spid-idps'))]);
        $response->assertJsonFragment(['entityId' => $this->app['config']->get('spid-idps.test')['entityId']]);
    }
    
    public function testProvidersWithoutTestIdp()
    {
        $this->app['config']->set('spid-auth.test_idp', false);
        $response = $this->get($this->providersURL);
        $response->assertJsonMissing(['entityId' => $this->app['config']->get('spid-idps.test')['entityId']]);
    }
}
