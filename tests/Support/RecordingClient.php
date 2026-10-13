<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Tests\Support;

use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Query\Query;
use SpacedTracker\Query\QueryResult;
use SpacedTracker\SpaceTrackClientInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingClient implements SpaceTrackClientInterface
{
    /** @var list<Query> */
    public array $queries = [];

    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function login(): void
    {
    }

    public function logout(): void
    {
    }

    public function hasSession(): bool
    {
        return true;
    }

    public function sessionExpiresAt(): ?\DateTimeImmutable
    {
        return null;
    }

    public function execute(Query $query): QueryResult
    {
        $this->queries[] = $query;

        return new QueryResult($query, 200, '[]', $this->records, null, 0.0, []);
    }

    public function executeMany(array $queries): array
    {
        return [];
    }

    public function modelDefinition(string $class, ?string $controller = null): array
    {
        return [];
    }

    public function uploadFile(
        string $folderId,
        string $filename,
        string $contents,
        string $contentType = 'application/octet-stream',
    ): QueryResult {
        unset($folderId, $filename, $contents, $contentType);

        return new QueryResult(Query::create(RequestClass::FileShareUpload), 201, '{}', [], null, 0.0, []);
    }

    public function sendAuthenticated(RequestInterface $request): ResponseInterface
    {
        unset($request);

        return new Response(200, [], '{}');
    }
}
