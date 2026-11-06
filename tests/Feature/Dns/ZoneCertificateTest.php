<?php

declare(strict_types=1);

use App\Actions\Dns\EnsureZoneCertificate;
use App\Actions\Dns\IssueZoneCertificate;
use App\Models\DnsZone;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeChallengeRecords(): void
{
    fakeCloudflare([
        ['id' => 'txt-1', 'content' => '"challenge-token"', 'comment' => 'databasify'],
        ['id' => 'theirs', 'content' => '"site-verification"', 'comment' => ''],
    ]);
}

it('proves the zone with a txt record, stores the wildcard and clears only its own record', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    fakeChallengeRecords();
    $authority = fakeCertificateAuthority();

    resolve(IssueZoneCertificate::class)->handle($zone);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['type'] === 'TXT'
        && $request['name'] === '_acme-challenge.db.example.com'
        && $request['content'] === '"challenge-token"'
        && $request['comment'] === 'databasify');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/txt-1'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/theirs'));
    expect($authority->issued)->toBe(['*.db.example.com'])
        ->and($zone->fresh()->hasCurrentCertificate())->toBeTrue()
        ->and($zone->fresh()->certificateBundle())->toStartWith('-----BEGIN CERTIFICATE-----')->toEndWith("-----\nzone-key\n");
});

it('clears the challenge record when let\'s encrypt refuses the order', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    fakeChallengeRecords();
    fakeCertificateAuthority()->failWith('too many certificates already issued');

    expect(fn () => resolve(IssueZoneCertificate::class)->handle($zone))->toThrow(RuntimeException::class, 'too many certificates already issued');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/txt-1'));
    expect($zone->fresh()->certificate)->toBeNull();
});

it('keeps a zone certificate that is still current', function () {
    $zone = withZoneCertificate(DnsZone::factory()->create(['name' => 'example.com']));
    Http::fake();
    $authority = fakeCertificateAuthority();

    resolve(EnsureZoneCertificate::class)->handle($zone);

    expect($authority->issued)->toBe([]);
    Http::assertNothingSent();
});

it('renews a zone certificate close to expiry', function () {
    $zone = withZoneCertificate(DnsZone::factory()->create(['name' => 'example.com']), days: 10);
    fakeChallengeRecords();
    $authority = fakeCertificateAuthority();

    resolve(EnsureZoneCertificate::class)->handle($zone);

    expect($authority->issued)->toBe(['*.db.example.com'])
        ->and($zone->fresh()->hasCurrentCertificate())->toBeTrue();
});

it('keeps the zone certificate key out of serialized zones', function () {
    $zone = withZoneCertificate(DnsZone::factory()->create(['name' => 'example.com']));

    expect($zone->toArray())->not->toHaveKeys(['certificate', 'certificate_key']);
});
