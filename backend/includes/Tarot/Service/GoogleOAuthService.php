<?php

namespace Tarot\Service;

use Tarot\Config\Env;

/**
 * Handles the Google OAuth2 server-side flow: building the authorization URL,
 * exchanging the authorization code for tokens, and fetching user info.
 *
 * Configuration comes from environment variables:
 *   GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI
 */
class GoogleOAuthService
{
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function isConfigured(): bool
    {
        return Env::get('GOOGLE_CLIENT_ID') !== null
            && Env::get('GOOGLE_CLIENT_SECRET') !== null
            && Env::get('GOOGLE_REDIRECT_URI') !== null;
    }

    public function getClientId(): ?string
    {
        return Env::get('GOOGLE_CLIENT_ID');
    }

    /**
     * Build the Google OAuth2 authorization URL.
     *
     * @param string $state An opaque, unguessable value to prevent CSRF.
     */
    public function buildAuthUrl(string $state): string
    {
        $params = [
            'client_id'     => Env::get('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => Env::get('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for an access token.
     *
     * @return array{access_token:string,id_token:string}|null
     */
    public function exchangeCode(string $code): ?array
    {
        $payload = [
            'code'          => $code,
            'client_id'     => Env::get('GOOGLE_CLIENT_ID'),
            'client_secret' => Env::get('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => Env::get('GOOGLE_REDIRECT_URI'),
            'grant_type'    => 'authorization_code',
        ];

        $response = $this->send(self::TOKEN_URL, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            return null;
        }

        // Normalize to the documented shape rather than passing Google's raw,
        // loosely-typed payload upward.
        return [
            'access_token' => $data['access_token'],
            'id_token'     => (string) ($data['id_token'] ?? ''),
        ];
    }

    /**
     * Fetch the authenticated user's profile from Google.
     *
     * The `email_verified` claim is included so callers can refuse to bind an
     * account to an unverified email (see GoogleAuthController::callback).
     *
     * @return array{sub:string,email:string,email_verified?:bool,name:string,picture:string}|null
     */
    public function fetchUserInfo(string $accessToken): ?array
    {
        $response = $this->send(self::USERINFO_URL, [CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]]);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (
            !is_array($data)
            || !is_string($data['sub'] ?? null) || $data['sub'] === ''
            || !is_string($data['email'] ?? null) || $data['email'] === ''
        ) {
            return null;
        }

        return [
            'sub'            => $data['sub'],
            'email'          => $data['email'],
            // Only a real true (or "true", as some Google endpoints spell booleans):
            // a (bool) cast read the string "false" as verified.
            'email_verified' => in_array($data['email_verified'] ?? false, [true, 'true'], true),
            'name'           => (string) ($data['name'] ?? ''),
            'picture'        => (string) ($data['picture'] ?? ''),
        ];
    }

    /**
     * One request to Google: the body of a 200 response, or null. Its own method
     * so the tests can answer in Google's place.
     *
     * @param array<int,mixed> $options curl options on top of the shared ones
     */
    protected function send(string $url, array $options): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode === 200 && is_string($response) ? $response : null;
    }
}
