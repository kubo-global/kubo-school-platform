<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use KuboKolibri\Client\KolibriClient;
use KuboKolibri\Services\KolibriProvisioner;
use KuboKolibri\Services\KolibriSessionBridge;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * With the kolibri-delegated-session plugin in Kolibri, the bridge asks Kolibri
 * for a learner's session as the admin and needs no learner password. Without
 * it, or when it does not answer, the derived password still opens the session.
 */
class KolibriDelegatedSessionTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    /** A KolibriClient whose HTTP layer is a mock returning $queued responses. */
    private function clientReturning(Response ...$queued): KolibriClient
    {
        $client = (new \ReflectionClass(KolibriClient::class))->newInstanceWithoutConstructor();

        $stack = HandlerStack::create(new MockHandler($queued));
        $stack->push(Middleware::history($this->sent));
        $http = new Client(['handler' => $stack, 'base_uri' => 'http://localhost:8080', 'cookies' => true]);

        foreach (['http' => $http, 'csrfToken' => 'ADMIN-CSRF'] as $name => $value) {
            $prop = (new \ReflectionClass(KolibriClient::class))->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($client, $value);
        }

        return $client;
    }

    #[Test]
    public function the_client_asks_the_plugin_for_the_learner_and_returns_the_session(): void
    {
        $client = $this->clientReturning(new Response(201, [], json_encode([
            'user_id' => 'learner-uuid',
            'session' => ['cookie' => 'kolibri', 'value' => 'SESSION123'],
            'csrf' => ['cookie' => 'kolibri_csrftoken', 'token' => 'CSRF456'],
        ])));

        $session = $client->openDelegatedSession('learner-uuid');

        $this->assertSame(['kolibri' => 'SESSION123', 'kolibri_csrftoken' => 'CSRF456'], $session);
        $request = $this->sent[0]['request'];
        $this->assertSame('/delegated-session/api/session/', $request->getUri()->getPath());
        $this->assertSame(['user' => 'learner-uuid'], json_decode((string) $request->getBody(), true));
        $this->assertSame('ADMIN-CSRF', $request->getHeaderLine('X-CSRFToken'));
    }

    #[Test]
    public function the_client_returns_null_when_the_plugin_is_absent_or_refuses(): void
    {
        $this->assertNull($this->clientReturning(new Response(404))->openDelegatedSession('learner-uuid'));
        $this->assertNull(
            $this->clientReturning(new Response(403, [], '[{"id":"TARGET_IS_NOT_A_LEARNER"}]'))
                ->openDelegatedSession('learner-uuid')
        );
        $this->assertNull($this->clientReturning(new Response(201, [], '{}'))->openDelegatedSession('learner-uuid'));
    }

    private function bridgeFor(User $pupil, KolibriClient $client): KolibriSessionBridge
    {
        School::factory()->create(['kolibri_facility_id' => 'facility-uuid']);
        $client->shouldReceive('proxyRenderUrl')->andReturn('/kolibri-proxy/x');

        $provisioner = Mockery::mock(KolibriProvisioner::class);
        $provisioner->shouldReceive('kolibriUsername')->andReturn('kubo_'.$pupil->id);
        $provisioner->shouldReceive('kolibriPassword')->andReturn('derived');

        return new KolibriSessionBridge($client, $provisioner);
    }

    private function pupil(): User
    {
        return User::factory()->create(['kolibri_user_id' => 'learner-uuid']);
    }

    #[Test]
    public function the_bridge_uses_the_delegated_session_and_no_password_when_switched_on(): void
    {
        config(['kubo-kolibri.delegated_session' => true]);
        $pupil = $this->pupil();
        $client = Mockery::mock(KolibriClient::class);
        $client->shouldReceive('openDelegatedSession')->once()->with('learner-uuid')
            ->andReturn(['kolibri' => 'DELEGATED', 'kolibri_csrftoken' => 'CSRF']);
        $client->shouldNotReceive('openSession');

        $data = $this->bridgeFor($pupil, $client)->exerciseSessionData($pupil, 'node');

        $this->assertTrue($data['ssoReady']);
        $this->assertStringContainsString('kolibri=DELEGATED', $data['cookieHeaders'][0]);
    }

    #[Test]
    public function the_bridge_falls_back_to_the_derived_password_when_kolibri_does_not_answer(): void
    {
        config(['kubo-kolibri.delegated_session' => true]);
        $pupil = $this->pupil();
        $client = Mockery::mock(KolibriClient::class);
        $client->shouldReceive('openDelegatedSession')->once()->andReturn(null);
        $client->shouldReceive('openSession')->once()->with('kubo_'.$pupil->id, 'derived', 'facility-uuid')
            ->andReturn(['kolibri' => 'BY-PASSWORD', 'kolibri_csrftoken' => null]);

        $data = $this->bridgeFor($pupil, $client)->exerciseSessionData($pupil, 'node');

        $this->assertStringContainsString('kolibri=BY-PASSWORD', $data['cookieHeaders'][0]);
    }

    #[Test]
    public function the_bridge_does_not_ask_the_plugin_when_switched_off(): void
    {
        config(['kubo-kolibri.delegated_session' => false]);
        $pupil = $this->pupil();
        $client = Mockery::mock(KolibriClient::class);
        $client->shouldNotReceive('openDelegatedSession');
        $client->shouldReceive('openSession')->once()
            ->andReturn(['kolibri' => 'BY-PASSWORD', 'kolibri_csrftoken' => null]);

        $data = $this->bridgeFor($pupil, $client)->exerciseSessionData($pupil, 'node');

        $this->assertTrue($data['ssoReady']);
    }
}
