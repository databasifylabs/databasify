<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\DTOs\Storage\S3Bucket;
use RuntimeException;

final readonly class S3
{
    private const string PAGE_SIZE = '1000';

    public function __construct(private S3Api $api) {}

    public static function make(S3Bucket $bucket): self
    {
        return new self(new S3Api($bucket));
    }

    public function put(string $key, string $body): void
    {
        $this->api->send('PUT', $key, $body);
    }

    public function get(string $key): string
    {
        return $this->api->send('GET', $key)->body();
    }

    public function delete(string $key): void
    {
        $this->api->send('DELETE', $key);
    }

    public function deleteAllUnder(string $prefix): int
    {
        $deleted = 0;

        while (($keys = $this->keysUnder($prefix)) !== []) {
            $this->deleteKeys($keys);
            $deleted += count($keys);
        }

        $this->deleteFolderIfEmpty($prefix);

        return $deleted;
    }

    public function deleteFolderIfEmpty(string $folder): void
    {
        if (! str_ends_with($folder, '/') || $this->keysUnder($folder) !== []) {
            return;
        }

        $this->delete($folder);
    }

    /**
     * @return list<string>
     */
    private function keysUnder(string $prefix): array
    {
        $listing = $this->api->send('GET', '', query: ['list-type' => '2', 'max-keys' => self::PAGE_SIZE, 'prefix' => $prefix])->body();
        preg_match_all('#<Key>([^<]*)</Key>#', $listing, $matches);

        return array_map(fn (string $key): string => html_entity_decode($key, ENT_QUOTES | ENT_XML1), $matches[1]);
    }

    /**
     * @param  list<string>  $keys
     */
    private function deleteKeys(array $keys): void
    {
        $body = $this->deleteRequestFor($keys);
        $result = $this->api->send('POST', '', $body, ['delete' => ''], ['Content-MD5' => base64_encode(md5($body, true))])->body();

        if (preg_match('#<Error>.*?<Key>([^<]*)</Key>.*?<Code>([^<]*)</Code>#s', $result, $error) === 1) {
            throw new RuntimeException("The bucket refused to delete {$error[1]}: {$error[2]}.");
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function deleteRequestFor(array $keys): string
    {
        $objects = implode('', array_map(fn (string $key): string => '<Object><Key>'.htmlspecialchars($key, ENT_XML1).'</Key></Object>', $keys));

        return "<Delete><Quiet>true</Quiet>{$objects}</Delete>";
    }
}
