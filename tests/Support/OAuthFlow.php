<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Access\AccessArea;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final readonly class OAuthFlow
{
    public const string REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    public string $verifier;

    public function __construct(private TestCase $test, public string $clientId)
    {
        $this->verifier = Str::random(64);
    }

    /**
     * @param  list<string>  $redirects
     */
    public static function register(TestCase $test, string $name = 'Claude', array $redirects = [self::REDIRECT]): self
    {
        $clientId = $test->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => $redirects])->json('client_id');

        return new self($test, is_string($clientId) ? $clientId : '');
    }

    /**
     * @param  array<string, string>  $levels
     * @param  list<string>|'all'  $projects
     */
    public static function connect(TestCase $test, User $owner, array $levels, array|string $projects = 'all'): OAuthFlowResult
    {
        $flow = self::register($test);
        $flow->signIn($owner);
        $code = $flow->codeFrom($flow->approve($flow->authToken(), $levels, $projects));

        return $flow->exchange($code);
    }

    public function signIn(User $owner): void
    {
        $this->test->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function authorize(string $state = 's1', string $redirect = self::REDIRECT): TestResponse
    {
        return $this->test->get('/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
    }

    public function authToken(): string
    {
        $token = $this->authorize()->viewData('page')['props']['authToken'] ?? null;

        return is_string($token) ? $token : '';
    }

    /**
     * @param  array<string, string>  $levels
     * @param  list<string>|'all'  $projects
     */
    public function approve(string $authToken, array $levels, array|string $projects = 'all'): TestResponse
    {
        return $this->test->post('/oauth/authorize', [
            'auth_token' => $authToken,
            'permissions' => self::permissions($levels),
            'projects' => $projects,
        ]);
    }

    public function codeFrom(TestResponse $response): string
    {
        return self::codeIn((string) $response->headers->get('Location'));
    }

    public static function codeIn(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return is_string($query['code'] ?? null) ? $query['code'] : '';
    }

    public function exchange(string $code): OAuthFlowResult
    {
        $tokens = $this->test->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $this->verifier,
            'code' => $code,
        ])->assertOk();

        return new OAuthFlowResult(
            (string) $tokens->json('access_token'),
            (string) $tokens->json('refresh_token'),
            OAuthClient::query()->findOrFail($this->clientId),
        );
    }

    /**
     * @param  array<string, string>  $levels
     * @return array<string, string>
     */
    public static function permissions(array $levels): array
    {
        return collect(AccessArea::cases())
            ->mapWithKeys(fn (AccessArea $area): array => [$area->value => $levels[$area->value] ?? 'none'])
            ->all();
    }
}
