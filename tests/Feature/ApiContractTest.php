<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\JsonSchemaLite;
use Tests\TestCase;

/**
 * The site's executor of the API contract: resources/spec/api-v1/cases.json, replayed.
 *
 * 🔴 The cases are the specification, shared with the mod (Core.Checks reads the same file for the
 * reader's half) and with the document (check-spec.py holds every case to openapi.json). They
 * travel as a copy in resources/spec/, put there by sync-common.ps1, refused by check-spec.py when
 * it diverges — the same road as the catalogue and the corpus.
 *
 * Each case: build `setup`, send `request` as the user it names, compare `response` — status,
 * the headers named, the body PARTIALLY (the keys the case names) — and then hold the WHOLE
 * answer to the operation's schema in openapi.json: required keys, types, closed words. The
 * partial match is what lets an additive field ship without touching a case; the schema is what
 * says the answer is complete.
 *
 * ⚠ One case, one test, one database: RefreshDatabase wraps each in a transaction, so a fixture
 * never sees another case's rows. The markers and the setup shape are described in the file.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private static ?array $document = null;

    private array $refs = [];

    private array $captures = [];

    private array $tokens = [];

    /** Files written for the fixtures, removed after the case. */
    private array $files = [];

    private static function spec(string $file): array
    {
        $path = dirname(__DIR__, 2) . '/resources/spec/api-v1/' . $file;
        self::assertFileExists($path, 'the spec copy is missing — run ./sync-common.ps1');

        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{array}> */
    public static function cases(): array
    {
        $doc = self::spec('cases.json');
        $out = [];
        foreach ($doc['cases'] as $case) {
            $setup = is_string($case['setup']) ? $doc['fixtures'][$case['setup']] : $case['setup'];
            $out[$case['id']] = [array_merge($case, ['setup' => $setup])];
        }

        return $out;
    }

    protected function tearDown(): void
    {
        // ⚠ The REAL local disk, not Storage::fake: the model resolves a translation's file with
        // storage_path (Translation::getSafeFilePath), which a faked disk does not move. Same as
        // every other API test here; what this one adds is cleaning up after itself.
        foreach ($this->files as $path) {
            Storage::disk('local')->delete($path);
        }
        parent::tearDown();
    }

    #[DataProvider('cases')]
    public function test_the_site_answers_as_the_case_says(array $case): void
    {
        $this->build($case['setup']);

        foreach ($case['prelude'] ?? [] as $step) {
            $response = $this->send($step['request']);
            foreach ($step['capture'] ?? [] as $name => $from) {
                [$where, $key] = explode(':', $from, 2);
                $this->captures[$name] = $where === 'header'
                    ? $response->headers->get($key)
                    : data_get($response->json(), $key);
                self::assertNotNull($this->captures[$name], "{$case['id']}: the prelude did not yield '$from' (status {$response->status()})");
            }
        }

        $response = $this->send($case['request']);
        $expected = $case['response'];
        $id = $case['id'];

        self::assertSame($expected['status'], $response->getStatusCode(),
            "$id — {$case['why']}\n" . mb_substr((string) $response->getContent(), 0, 600));

        foreach ($expected['headers'] ?? [] as $name => $matcher) {
            $detail = $this->agrees($matcher, $response->headers->get($name), "header $name");
            self::assertSame('', $detail, "$id — $detail — {$case['why']}");
        }

        if (array_key_exists('body', $expected)) {
            // A streamed answer (the edit session's file) has no content until it is played.
            $raw = $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
                ? $response->streamedContent()
                : $response->getContent();
            $actual = json_decode($raw, true);
            self::assertIsArray($actual, "$id — the answer is not JSON: " . mb_substr((string) $raw, 0, 300));
            $detail = $this->agrees($expected['body'], $actual, '$');
            self::assertSame('', $detail, "$id — $detail — {$case['why']}");

            // The whole answer, not only the keys the case names: what the document promises a
            // client may rely on has to be there, with the right type and the right words.
            $schema = $this->responseSchema($case['request'], $expected['status']);
            if ($schema !== null) {
                $errors = (new JsonSchemaLite(self::document()))->errors($schema, $actual);
                self::assertSame([], $errors, "$id — the answer does not fit openapi.json: " . implode(' | ', array_slice($errors, 0, 4)));
            }
        }
    }

    /**
     * 🔴 The coverage rule, like `sides` in the corpus: a route the document does not name is a
     * route a second Core cannot know, and a path the site does not serve is a promise to nobody.
     */
    public function test_the_document_names_every_route_and_no_other(): void
    {
        $served = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $served[] = $method . ' /' . substr($route->uri(), strlen('api/v1/'));
            }
        }

        $described = [];
        foreach (self::document()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $described[] = strtoupper($method) . ' ' . $path;
                }
            }
        }

        sort($served);
        sort($described);
        self::assertSame($served, $described,
            'openapi.json and routes/api.php disagree — served but not described: ' . json_encode(array_values(array_diff($served, $described)))
            . ' / described but not served: ' . json_encode(array_values(array_diff($described, $served))));
    }

    // ── Setup ──────────────────────────────────────────────────────────────────────────────

    private function build(array $setup): void
    {
        $service = new TranslationService();

        foreach ($setup['users'] ?? [] as $ref => $spec) {
            $user = User::factory()->create(array_filter([
                'name' => $spec['name'],
                'account_deleted_at' => ($spec['deleted'] ?? false) ? now() : null,
                'banned_at' => ($spec['banned'] ?? false) ? now() : null,
            ], fn ($v) => $v !== null));
            $this->refs["users.$ref.id"] = $user->id;
            $this->refs["users.$ref"] = $user;
        }

        foreach ($setup['games'] ?? [] as $ref => $spec) {
            $game = Game::create(['name' => $spec['name'], 'slug' => $spec['slug'], 'steam_id' => $spec['steam_id'] ?? null]);
            $this->refs["games.$ref.id"] = $game->id;
            $this->refs["games.$ref"] = $game;
        }

        foreach ($setup['translations'] ?? [] as $ref => $spec) {
            $json = array_merge(['_uuid' => $spec['uuid']], $spec['lines']);
            $path = 'translations/contract-' . $ref . '-' . uniqid() . '.json';
            Storage::disk('local')->put($path, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->files[] = $path;

            $translation = new Translation();
            $translation->forceFill(array_merge([
                'game_id' => $this->refs["games.{$spec['game']}.id"],
                'user_id' => $this->refs["users.{$spec['user']}.id"],
                'parent_id' => isset($spec['parent']) ? $this->refs["translations.{$spec['parent']}.id"] : null,
                'file_uuid' => $spec['uuid'],
                'visibility' => $spec['visibility'],
                'status' => $spec['status'] ?? 'in_progress',
                'accepts_branches' => $spec['accepts_branches'] ?? false,
                'notes' => $spec['notes'] ?? null,
                'resources_url' => $spec['resources_url'] ?? null,
                'source_language' => $spec['source_language'] ?? 'English',
                'target_language' => $spec['target_language'] ?? 'French',
                'file_path' => $path,
                'file_hash' => $spec['file_hash'],
                'content_hash' => $service->computeContentHash($json),
                'line_count' => $service->countLines($json),
                'vote_count' => $spec['vote_count'] ?? 0,
            ], Translation::extractTagCounts($json)))->save();

            $this->refs["translations.$ref.id"] = $translation->id;
            $this->refs["translations.$ref.file_hash"] = $translation->file_hash;
            $this->refs["translations.$ref"] = $translation;
        }

        foreach ($setup['votes'] ?? [] as $vote) {
            $this->refs["translations.{$vote['translation']}"]->vote($vote['value'], $this->refs["users.{$vote['user']}"]);
        }
    }

    // ── Sending ────────────────────────────────────────────────────────────────────────────

    private function send(array $request): TestResponse
    {
        $uri = '/api/v1' . ltrim($this->interpolate($request['path']), '/');
        $uri = '/api/v1/' . ltrim(substr($uri, strlen('/api/v1')), '/');
        if (!empty($request['query'])) {
            $uri .= '?' . http_build_query(array_map(fn ($v) => $this->interpolate((string) $v), $request['query']));
        }

        $headers = ['Accept' => 'application/json'];
        foreach ($request['headers'] ?? [] as $name => $value) {
            $headers[$name] = $this->interpolate((string) $value);
        }
        if (isset($request['as'])) {
            $headers['Authorization'] = 'Bearer ' . $this->tokenFor($request['as']);
        }

        $body = array_key_exists('body', $request) ? $this->resolveBody($request['body']) : null;

        if (($request['body_encoding'] ?? null) === 'gzip') {
            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_ENCODING' => 'gzip'];
            foreach ($headers as $name => $value) {
                $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            }

            return $this->call($request['method'], $uri, [], [], [], $server, gzencode(json_encode($body, JSON_UNESCAPED_UNICODE)));
        }

        return $this->json($request['method'], $uri, $body ?? [], $headers);
    }

    private function tokenFor(string $userRef): string
    {
        return $this->tokens[$userRef] ??= ApiToken::createForUser($this->refs["users.$userRef"], 'contract')->plain_token;
    }

    /** `{{a.b.c}}` → a setup value or a prelude capture, inside a string. */
    private function interpolate(string $text): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function ($m) {
            $name = $m[1];
            if (array_key_exists($name, $this->captures)) {
                return (string) $this->captures[$name];
            }
            self::assertArrayHasKey($name, $this->refs, "no setup value or capture named '$name'");

            return (string) $this->refs[$name];
        }, $text);
    }

    /** The request body with `$ref` and `$json` resolved, at every depth. */
    private function resolveBody(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_key_exists('$ref', $value)) {
            self::assertArrayHasKey($value['$ref'], $this->refs, "no setup value named '{$value['$ref']}'");

            return $this->refs[$value['$ref']];
        }
        if (array_key_exists('$json', $value)) {
            return json_encode($value['$json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return array_map(fn ($v) => $this->resolveBody($v), $value);
    }

    // ── Matching ───────────────────────────────────────────────────────────────────────────

    /** '' when the actual value agrees with the matcher, otherwise what disagrees, and where. */
    private function agrees(mixed $expected, mixed $actual, string $path): string
    {
        if (is_array($expected) && !array_is_list($expected)) {
            if (array_key_exists('$is', $expected)) {
                return $this->isOfKind($actual, $expected['$is']) ? '' : "$path: expected {$expected['$is']}, got " . json_encode($actual);
            }
            if (array_key_exists('$ref', $expected)) {
                $wanted = $this->refs[$expected['$ref']] ?? null;

                return $actual == $wanted ? '' : "$path: expected {$expected['$ref']} (" . json_encode($wanted) . '), got ' . json_encode($actual);
            }
            if (array_key_exists('$contains', $expected)) {
                return is_string($actual) && str_contains($actual, $expected['$contains']) ? '' : "$path: " . json_encode($actual) . " does not contain '{$expected['$contains']}'";
            }
            if (array_key_exists('$any', $expected)) {
                return '';
            }

            if (!is_array($actual) || ($actual !== [] && array_is_list($actual))) {
                return "$path: expected an object, got " . json_encode($actual);
            }
            foreach ($expected as $key => $sub) {
                if (is_array($sub) && array_key_exists('$absent', $sub)) {
                    if (array_key_exists($key, $actual)) {
                        return "$path.$key: must be absent, got " . json_encode($actual[$key]);
                    }
                    continue;
                }
                if (!array_key_exists($key, $actual)) {
                    return "$path.$key: missing";
                }
                $detail = $this->agrees($sub, $actual[$key], "$path.$key");
                if ($detail !== '') {
                    return $detail;
                }
            }

            return '';
        }

        if (is_array($expected)) {
            if (!is_array($actual) || ($actual !== [] && !array_is_list($actual))) {
                return "$path: expected a list, got " . json_encode($actual);
            }
            if (count($actual) !== count($expected)) {
                return "$path: expected " . count($expected) . ' item(s), got ' . count($actual);
            }
            foreach ($expected as $i => $sub) {
                $detail = $this->agrees($sub, $actual[$i], "$path\[$i]");
                if ($detail !== '') {
                    return $detail;
                }
            }

            return '';
        }

        return $actual === $expected ? '' : "$path: expected " . json_encode($expected) . ', got ' . json_encode($actual);
    }

    private function isOfKind(mixed $value, string $kind): bool
    {
        return match ($kind) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'iso8601' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1,
            'file_hash' => is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1,
            'session_key' => is_string($value) && preg_match('/^[A-Za-z0-9]{64}$/', $value) === 1,
            'url' => is_string($value) && str_starts_with($value, 'http'),
            'any' => true,
            default => throw new \InvalidArgumentException("unknown matcher kind '$kind'"),
        };
    }

    // ── The document ───────────────────────────────────────────────────────────────────────

    private static function document(): array
    {
        return self::$document ??= self::spec('openapi.json');
    }

    /** The schema of the JSON answer the document promises for this request and status, or null. */
    private function responseSchema(array $request, int $status): ?array
    {
        $document = self::document();
        $wanted = array_values(array_filter(explode('/', explode('?', $request['path'])[0]), fn ($s) => $s !== ''));
        $best = null;
        $bestLiterals = -1;

        foreach ($document['paths'] as $template => $operations) {
            $segments = array_values(array_filter(explode('/', $template), fn ($s) => $s !== ''));
            $method = strtolower($request['method']);
            if (count($segments) !== count($wanted) || !isset($operations[$method])) {
                continue;
            }
            $literals = 0;
            $ok = true;
            foreach ($segments as $i => $segment) {
                if (str_starts_with($segment, '{')) {
                    continue;
                }
                if ($segment !== $wanted[$i]) {
                    $ok = false;
                    break;
                }
                $literals++;
            }
            if ($ok && $literals > $bestLiterals) {
                $best = $operations[$method];
                $bestLiterals = $literals;
            }
        }
        self::assertNotNull($best, "no operation for {$request['method']} {$request['path']} in openapi.json");

        $entry = $best['responses'][(string) $status] ?? $document['x-common-responses'][(string) $status] ?? null;
        self::assertNotNull($entry, "status $status is not described for {$request['method']} {$request['path']}");
        if (isset($entry['$ref'])) {
            $entry = (new JsonSchemaLite($document))->resolve($entry['$ref']);
        }

        return $entry['content']['application/json']['schema'] ?? null;
    }
}
