<?php

declare(strict_types=1);

namespace App\Services\Acme;

use Afosto\Acme\Client;
use Afosto\Acme\Data\Authorization;
use Afosto\Acme\Data\Challenge;
use Afosto\Acme\Data\Order;
use App\Services\Http\Payload;

class AcmeClient extends Client
{
    /**
     * @return list<Authorization>
     */
    public function authorize(Order $order): array
    {
        return array_values(array_map($this->authorizationAt(...), $order->getAuthorizationURLs()));
    }

    protected function tosAgree(): void
    {
        $this->request(
            $this->getUrl(self::DIRECTORY_NEW_ACCOUNT),
            $this->signPayloadJWK(['termsOfServiceAgreed' => true], $this->getUrl(self::DIRECTORY_NEW_ACCOUNT)),
        );
    }

    private function authorizationAt(string $url): Authorization
    {
        $data = Payload::of(json_decode((string) $this->request($url, $this->signPayloadKid(null, $url))->getBody(), true));
        $authorization = new Authorization($data->item('identifier')->string('value'), $data->string('expires'), $this->getDigest());

        collect($data->items('challenges'))
            ->filter(fn (Payload $challenge): bool => $challenge->stringOrNull('token') !== null)
            ->each(fn (Payload $challenge) => $authorization->addChallenge($this->challengeFrom($url, $challenge)));

        return $authorization;
    }

    private function challengeFrom(string $authorizationUrl, Payload $challenge): Challenge
    {
        return new Challenge(
            $authorizationUrl,
            $challenge->string('type'),
            $challenge->string('status'),
            $challenge->string('url'),
            $challenge->string('token'),
        );
    }
}
