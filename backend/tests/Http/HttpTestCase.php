<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use DI\Container;
use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tarot\Repository\UserRepository;
use Tarot\Structure\User;
use Tarot\Utility\Session;

/**
 * Dispatches PSR-7 requests through the real app — api/app.php's middleware,
 * routing, controllers, services and Data layer — against an in-memory SQLite
 * database built from db/schema.sql (the production schema), foreign keys on as in
 * production.
 *
 * Each test is one browser: the session id a request leaves behind goes out with
 * the next one, as the cookie would, and the session itself is stored in the test
 * database by DbSessionHandler, so sign-in, logout and strict-mode id checks run
 * for real. Nothing here touches the developer's database or .env.
 */
abstract class HttpTestCase extends TestCase
{
    protected const string HOST = 'tarotgen.test';

    /**
     * Every setting the app reads, blanked for each test ('' counts as unset) so the
     * developer's shell can't change the outcome: no SMTP, no Turnstile, no OAuth,
     * not production.
     */
    private const array ENV_KEYS = [
        'APP_ENV', 'APP_URL', 'APP_ORIGIN', 'CSP_MODE', 'SMTP_HOST',
        'CLOUDFLARE_TURNSTILE_SITEKEY', 'CLOUDFLARE_TURNSTILE_SECRET',
        'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'PROTECTED_ADMIN_EMAILS',
    ];

    protected PDO $db;

    private Container $container;

    /** @var App<ContainerInterface|null>|null Built on first use, so a test can set env first. */
    private ?App $app = null;

    /** What the browser's session cookie holds; null before any session exists. */
    private ?string $sessionId = null;

    /**
     * The rate limiters are file-backed in the system temp dir and keyed by client
     * IP, so each test gets its own documentation-range address and can't trip over
     * another test's (or the local dev server's) counters.
     */
    private string $clientIp;

    /** @var array<string,mixed> */
    private array $savedEnv = [];

    private string|false $savedErrorLog = false;

    private string $errorLog;

    /** @var array<string,mixed> openapi.json, read once for assertDocumented(). */
    private static array $spec = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = '';
        }

        // Expected failures (Slim's error logging, the mailer reporting that SMTP is
        // unconfigured) would otherwise print to the test output.
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'tarot-http-log');
        $this->savedErrorLog = ini_set('error_log', $this->errorLog);

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql'));
        $this->db->exec('PRAGMA foreign_keys = ON');

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions(dirname(__DIR__, 2) . '/api/dependencies.php');
        $builder->addDefinitions([PDO::class => $this->db]);
        $this->container = $builder->build();

        // app.php does this too; doing it here as well means a session started
        // before the first request (signInAs) can never reach the real database.
        $db = $this->db;
        Session::useDatabase(static fn(): PDO => $db);

        $this->clientIp = '2001:db8::' . bin2hex(random_bytes(2)) . ':' . bin2hex(random_bytes(2));
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        ini_set('error_log', $this->savedErrorLog === false ? '' : $this->savedErrorLog);
        @unlink($this->errorLog);
    }

    /** Set an env value the app reads; applies from the next request on. */
    protected function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $this->app = null;
    }

    /**
     * A service from the app's container (wired to the test database).
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        return $this->container->get($id);
    }

    /**
     * Put a stand-in in the container (an object that talks to the outside world,
     * like Google or SMTP). Applies from the next request on; set it before anything
     * that depends on it is first resolved, since resolved services are cached.
     */
    protected function replaceService(string $id, object $instance): void
    {
        $this->container->set($id, $instance);
        $this->app = null;
    }

    /**
     * @param array<string,mixed>|null $json  Sent as the JSON request body.
     * @param array<string,string>     $headers
     */
    protected function request(string $method, string $path, ?array $json = null, array $headers = []): ResponseInterface
    {
        return $json === null
            ? $this->send($method, $path, null, $headers)
            : $this->send($method, $path, ['application/json', json_encode($json, JSON_THROW_ON_ERROR)], $headers);
    }

    /**
     * POST form-encoded fields, as the site's New Draw form does (URLSearchParams).
     *
     * @param array<string,string> $fields
     * @param array<string,string> $headers
     */
    protected function postForm(string $path, array $fields, array $headers = []): ResponseInterface
    {
        return $this->send('POST', $path, ['application/x-www-form-urlencoded', http_build_query($fields)], $headers);
    }

    /**
     * @param array{string,string}|null $body Content type and the encoded body.
     * @param array<string,string>      $headers
     */
    private function send(string $method, string $path, ?array $body, array $headers): ResponseInterface
    {
        $request = new DecoratedServerRequestFactory(new ServerRequestFactory())
            ->createServerRequest($method, 'https://' . self::HOST . '/api' . $path, ['REMOTE_ADDR' => $this->clientIp])
            ->withHeader('Host', self::HOST)
            ->withHeader('Accept', 'application/json');

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', $body[0])
                ->withBody(new StreamFactory()->createStream($body[1]));
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        session_id($this->sessionId ?? '');

        try {
            return $this->app()->handle($request);
        } finally {
            $this->endRequest();
        }
    }

    /** @param array<string,string> $headers */
    protected function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * @param array<string,mixed>  $json
     * @param array<string,string> $headers
     */
    protected function post(string $path, array $json = [], array $headers = []): ResponseInterface
    {
        return $this->request('POST', $path, $json, $headers);
    }

    /**
     * Assert the status (showing the body when it differs) and return the decoded
     * JSON body.
     *
     * @return array<mixed>
     */
    protected function assertJsonResponse(int $status, ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $this->assertSame($status, $response->getStatusCode(), "Unexpected status; body: {$body}");
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /**
     * Read the test database directly, to check what a request stored.
     *
     * @return list<array<string,mixed>>
     */
    protected function rows(string $sql): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    protected function rowCount(string $table): int
    {
        return count($this->rows("SELECT 1 FROM {$table}"));
    }

    /** A user with a placeholder hash: it can't sign in by password, only via signInAs(). */
    protected function createUser(string $email, bool $admin = false, bool $active = true): User
    {
        $users = $this->service(UserRepository::class);
        $local = strstr($email, '@', true);
        $user = $users->create($email, $local === false ? $email : $local, 'not-a-password-hash');
        $this->assertNotNull($user);

        if ($active) {
            $users->activate($user->user_id);
        }
        if ($admin) {
            $users->setAdmin($user->user_id, true);
        }

        $fresh = $users->findById($user->user_id);
        $this->assertNotNull($fresh);
        return $fresh;
    }

    /** Leave the browser signed in as $userId, as a successful login would. */
    protected function signInAs(int $userId): void
    {
        session_id($this->sessionId ?? '');
        Session::start();
        Session::regenerate();
        $_SESSION['user_id'] = $userId;
        $this->endRequest();
    }

    /** The session id the browser's cookie holds, if any. */
    protected function sessionCookie(): ?string
    {
        return $this->sessionId;
    }

    /** Send $sessionId as the cookie from now on (null: a browser with no cookie). */
    protected function useSessionCookie(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * The payload matches what the committed openapi.json documents for this
     * response: a schema $ref (one of them, for a oneOf), an array of one, an
     * allOf, or an inline object — nested schemas included.
     *
     * @param array<mixed> $payload
     */
    protected function assertDocumented(string $method, string $path, int $status, array $payload): void
    {
        $schema = self::spec()['paths'][$path][$method]['responses'][(string) $status]['content']['application/json']['schema'] ?? null;
        $this->assertIsArray($schema, "{$method} {$path} documents no JSON body for {$status}");

        $problems = [];
        foreach ($schema['oneOf'] ?? [$schema] as $candidate) {
            $found = $this->schemaProblems($candidate, $payload);
            if ($found === []) {
                $this->addToAssertionCount(1);
                return;
            }
            $problems[] = ($candidate['$ref'] ?? 'inline schema') . ': ' . implode('; ', $found);
        }
        $this->fail("{$method} {$path} {$status} doesn't match its documented schema —\n" . implode("\n", $problems));
    }

    /**
     * Everything that makes $payload differ from $schema: a required property
     * missing, an undocumented one present, or a nested schema that doesn't match.
     *
     * @param array<mixed> $schema
     * @param array<mixed> $payload
     * @return list<string>
     */
    private function schemaProblems(array $schema, array $payload): array
    {
        if (isset($schema['$ref'])) {
            $ref = (string) $schema['$ref'];
            $schema = self::spec()['components']['schemas'][basename($ref)] ?? null;
            if (!is_array($schema)) {
                return ["no schema {$ref}"];
            }
        }

        if (isset($schema['allOf'])) {
            // Every member applies at once, so check against them merged: alone,
            // each would call the others' fields undocumented.
            $merged = ['properties' => [], 'required' => []];
            foreach ($schema['allOf'] as $member) {
                if (isset($member['$ref'])) {
                    $ref = (string) $member['$ref'];
                    $member = self::spec()['components']['schemas'][basename($ref)] ?? null;
                    if (!is_array($member)) {
                        return ["no schema {$ref}"];
                    }
                }
                $merged['properties'] += $member['properties'] ?? [];
                $merged['required'] = [...$merged['required'], ...$member['required'] ?? []];
            }
            $schema = $merged;
        }

        if (($schema['type'] ?? null) === 'array') {
            if (!array_is_list($payload)) {
                return ['an object where a list is documented'];
            }
            $problems = [];
            foreach ($payload as $i => $item) {
                foreach ($this->schemaProblems($schema['items'] ?? [], is_array($item) ? $item : []) as $problem) {
                    $problems[] = "[{$i}].{$problem}";
                }
            }
            return $problems;
        }

        $properties = $schema['properties'] ?? [];
        $problems = [];
        foreach (array_diff($schema['required'] ?? [], array_keys($payload)) as $missing) {
            $problems[] = "missing {$missing}";
        }
        foreach (array_diff(array_keys($payload), array_keys($properties)) as $extra) {
            $problems[] = "undocumented {$extra}";
        }
        foreach ($properties as $property => $definition) {
            $nested = isset($definition['$ref']) || isset($definition['items']['$ref']);
            if (!$nested || !is_array($payload[$property] ?? null)) {
                continue;
            }
            foreach ($this->schemaProblems($definition, $payload[$property]) as $problem) {
                $problems[] = "{$property}.{$problem}";
            }
        }
        return $problems;
    }

    /** @return array<string,mixed> */
    private static function spec(): array
    {
        if (self::$spec === []) {
            $spec = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$spec = is_array($spec) ? $spec : [];
        }
        return self::$spec;
    }

    /** @return App<ContainerInterface|null> */
    private function app(): App
    {
        return $this->app ??= (require dirname(__DIR__, 2) . '/api/app.php')($this->container, false);
    }

    /**
     * What PHP does when a request ends: write and close the session. The browser
     * keeps whatever id it ended on (a login or logout regenerates it).
     */
    private function endRequest(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $id = session_id();
            $this->sessionId = $id === false || $id === '' ? null : $id;
            session_write_close();
        }
        $_SESSION = [];
    }
}
