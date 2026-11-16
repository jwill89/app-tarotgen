<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Middleware\OriginGuard;

/**
 * What every request goes through before and after its controller: the
 * cross-origin guard (outermost) and the security and CORS headers. How errors
 * answer is ProblemDetailsTest's.
 */
#[CoversClass(OriginGuard::class)]
final class RequestPipelineTest extends HttpTestCase
{
    private const array FOREIGN = ['Origin' => 'https://evil.example'];

    public function testAForeignOriginCannotChangeState(): void
    {
        $response = $this->post('/contacts', ['name' => 'X', 'email' => 'x@example.test', 'message' => 'Hi'], self::FOREIGN);

        $this->assertSame('Cross-origin request blocked.', $this->assertProblem(403, $response));
        $this->assertSame(0, $this->rowCount('contacts'));
    }

    public function testTheGuardRunsBeforeRoutingAndAuth(): void
    {
        // A foreign POST learns nothing: not whether the route exists, not whether it needs a login.
        $this->assertProblem(403, $this->post('/no-such-route', [], self::FOREIGN));
        $this->assertProblem(403, $this->post('/account/spreads', ['name' => 'X'], self::FOREIGN));
        $this->assertProblem(403, $this->request('DELETE', '/admin/readings/all', null, self::FOREIGN));
    }

    public function testAForeignRefererIsBlockedWhenThereIsNoOrigin(): void
    {
        $response = $this->post('/card-reports', [], ['Referer' => 'https://evil.example/page']);

        $this->assertProblem(403, $response);
    }

    public function testSameOriginAndSafeRequestsPass(): void
    {
        $sameOrigin = ['Origin' => 'https://' . self::HOST];
        $this->assertProblem(422, $this->post('/card-reports', [], $sameOrigin));

        // No Origin and no Referer: not a browser, so not a CSRF vector.
        $this->assertProblem(422, $this->post('/card-reports', []));

        // Reads are never blocked.
        $this->assertJsonResponse(200, $this->get('/config', self::FOREIGN));
    }

    public function testAConfiguredAppOriginIsAllowedAndGetsCorsHeaders(): void
    {
        $this->assertSame('', $this->get('/config')->getHeaderLine('Access-Control-Allow-Origin'));

        $this->setEnv('APP_ORIGIN', 'https://app.example');

        $response = $this->post('/card-reports', [], ['Origin' => 'https://app.example']);
        $this->assertProblem(422, $response);
        $this->assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));

        $this->assertProblem(403, $this->post('/card-reports', [], self::FOREIGN));
    }

    public function testResponsesCarryTheSecurityHeaders(): void
    {
        foreach ([$this->get('/config'), $this->get('/account/spreads'), $this->get('/no-such-route')] as $response) {
            $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
            $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        }
    }

}
