<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedApps;

use App\Actions\Api\ApproveConnectedApp;
use App\Enums\Access\AccessArea;
use App\Enums\Mcp\AiAgent;
use App\Http\Requests\ConnectedApps\ApproveConnectedAppRequest;
use App\Models\OAuthClient;
use App\Models\User;
use App\Queries\Projects\ProjectQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\RetrievesAuthRequestFromSession;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeConnectedAppController extends AuthorizationController
{
    use RetrievesAuthRequestFromSession;

    public function approve(ApproveConnectedAppRequest $request, #[CurrentUser] User $owner, ApproveConnectedApp $approve, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);
        $grant = $request->toAccessGrant();
        $client = OAuthClient::query()->findOrFail($authRequest->getClient()->getIdentifier());

        $authRequest->setScopes($this->bridgeScopes($approve->handle($owner, $client, $grant)));
        $authRequest->setAuthorizationApproved(true);

        return $this->redirectBack($authRequest, $psrResponse);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function page(array $parameters): Response
    {
        $client = $parameters['client'] ?? null;
        $request = $parameters['request'] ?? null;

        if (! $client instanceof OAuthClient || ! $request instanceof Request) {
            throw new LogicException('Passport passes the client and the request to the authorization view.');
        }

        return Inertia::render('auth/authorize-connected-app', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'icon' => AiAgent::forClient($client)?->icon(),
                'redirect_host' => OAuthClient::hostOf($request->string('redirect_uri')->value() ?: ($client->redirect_uris[0] ?? '')),
            ],
            'areas' => AccessArea::options(),
            'projects' => ProjectQuery::links()->get()->map->only(['id', 'name'])->all(),
            'authToken' => $parameters['authToken'] ?? null,
        ])->toResponse($request);
    }

    public function deny(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);
        $authRequest->setAuthorizationApproved(false);

        return $this->redirectBack($authRequest, $psrResponse);
    }

    protected function approveRequest(AuthorizationRequestInterface $authRequest, ResponseInterface $psrResponse): Response
    {
        $client = OAuthClient::query()->findOrFail($authRequest->getClient()->getIdentifier());
        $authRequest->setScopes($this->bridgeScopes($client->scopes ?? []));

        return parent::approveRequest($authRequest, $psrResponse);
    }

    /**
     * @param  list<non-empty-string>  $scopes
     * @return list<Scope>
     */
    private function bridgeScopes(array $scopes): array
    {
        return array_map(fn (string $scope): Scope => new Scope($scope), $scopes);
    }

    private function redirectBack(AuthorizationRequestInterface $authRequest, ResponseInterface $psrResponse): Response
    {
        try {
            $response = $this->server->completeAuthorizationRequest($authRequest, $psrResponse);
        } catch (OAuthServerException $exception) {
            $response = $exception->generateHttpResponse($psrResponse);
        }

        return Inertia::location($response->getHeaderLine('Location'));
    }
}
