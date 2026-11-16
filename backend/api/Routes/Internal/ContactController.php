<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Routes\Problem;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Exception\ApiException;
use Tarot\Repository\ContactRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\TurnstileService;
use Tarot\Utility\Input;
use Tarot\Utility\RateLimiter;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

class ContactController extends AbstractController
{
    // Public submissions: max 5 per IP per hour.
    private const int SUBMIT_MAX_PER_WINDOW = 5;
    private const int SUBMIT_WINDOW_SECONDS = 3600;

    /** The longest name and address a message keeps. */
    private const int NAME_MAX = 200;
    private const int EMAIL_MAX = 320;

    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly UserRepository $users,
        private readonly TurnstileService $turnstile,
    ) {}

    #[OAS\Operation\Post(
        path: '/contacts',
        summary: 'Submit a contact-form message (rate-limited: 5/IP/hour)',
        tags: ['Contact'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name', 'email', 'message'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: self::NAME_MAX)),
                    new OAS\Property(property: 'email', schema: new OAS\Schema(type: 'string', format: 'email', maxLength: self::EMAIL_MAX)),
                    new OAS\Property(property: 'message', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'turnstile_token', schema: new OAS\Schema(type: 'string', description: 'Required when Turnstile is configured')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Submitted',
                content: new OAS\MediaType\Json(required: ['success'], properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 422, description: 'Missing/invalid fields, or captcha failed'),
            new OAS\Response(response: 429, description: 'Rate limit exceeded: 5 messages/IP/hour'),
        ]
    )]
    /**
     * Public endpoint: submit a contact form message.
     * Rate-limited per IP to prevent spam.
     */
    public function submit(Request $request, Response $response): Response|ResponseInterface
    {
        $ip = $this->clientIp($request);

        $limiter = new RateLimiter('contact_submit', self::SUBMIT_MAX_PER_WINDOW, self::SUBMIT_WINDOW_SECONDS);

        if ($limiter->isLimited($ip)) {
            return Problem::tooManyRequests(
                $response,
                'You have submitted too many messages recently. Please try again later.',
                $limiter->retryAfter($ip)
            );
        }

        $params = $this->parsedBody($request);

        try {
            $name    = Input::text($params['name'] ?? null, self::NAME_MAX, 'body.name');
            $email   = Input::text($params['email'] ?? null, self::EMAIL_MAX, 'body.email');
            $message = Input::text($params['message'] ?? null, PHP_INT_MAX, 'body.message');
        } catch (ApiException $e) {
            return Problem::fromException($response, $e);
        }

        $missing = array_filter([
            'body.name'    => $name === '' ? 'Name is required.' : '',
            'body.email'   => $email === '' ? 'Email is required.' : '',
            'body.message' => $message === '' ? 'Message is required.' : '',
        ], static fn(string $error): bool => $error !== '');
        if ($missing !== []) {
            return Problem::respond($response, 422, 'Name, email, and message are all required.', $missing);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return Problem::invalid($response, 'body.email', 'Please provide a valid email address.');
        }

        // Bot guard, as on sign-in: when Turnstile is configured, a valid challenge
        // token is required before anything is stored. Checked after the cheap field
        // checks, so a malformed request costs no call to Cloudflare. A failed
        // challenge is a refused message: like the others, it isn't counted.
        if ($this->turnstile->isConfigured()) {
            $token = (string) ($params['turnstile_token'] ?? '');
            if (!$this->turnstile->verify($token, $ip)) {
                return Problem::invalid($response, 'body.turnstile_token', 'Captcha verification failed. Please try again.');
            }
        }

        $userId = $this->currentUserId();

        $contact = $this->contacts->create([
            'user_id' => $userId,
            'name'    => $name,
            'email'   => $email,
            'message' => $message,
        ]);

        if ($contact === null) {
            return Problem::respond($response, 500, 'Failed to submit your message. Please try again.');
        }

        // Only count successful submissions against the limit.
        $limiter->hit($ip);

        return $response->withJson(['success' => true], 201);
    }

    /**
     * The logged-in user's id, or null for a guest submission. A deleted account
     * leaves its sessions behind, and its id would fail the foreign key: that
     * visitor's message goes in as a guest's rather than being lost to a 500.
     */
    private function currentUserId(): ?int
    {
        Session::start();
        $userId = Session::userId();

        return $userId !== null && $this->users->findById($userId) !== null ? $userId : null;
    }
}
