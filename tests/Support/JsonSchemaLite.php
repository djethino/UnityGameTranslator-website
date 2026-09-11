<?php

namespace Tests\Support;

/**
 * Enough of JSON Schema 2020-12 to hold a real API answer to resources/spec/api-v1/openapi.json.
 *
 * ⚠ Not a validator to reach for elsewhere: it knows the keywords that document uses — `$ref`
 * into the document, `type` (one or several), `required`, `properties`, `additionalProperties`,
 * `propertyNames`, `items`, `minItems`/`maxItems`, `oneOf`/`anyOf`, `const`, `enum`, `pattern`,
 * `minimum`, `minLength`/`maxLength` — and nothing else, and it says so when it meets a keyword it
 * does not. It exists because the site ships no JSON Schema library (the framework's is a dev
 * dependency of the framework, not of the site) and adding one to every deployment for a test is
 * the wrong trade; `check-spec.py` runs the full validator over the CASES, this runs the subset
 * over what the site ACTUALLY answered.
 *
 * 🔴 PHP decodes JSON into arrays, so `{}` and `[]` are the same value. An empty array is taken to
 * be whichever of object or array the schema asks for — which is also exactly how the site emits
 * an empty tally (`"differing": []`, see TagTally in the document).
 */
final class JsonSchemaLite
{
    private const KNOWN = [
        '$ref', 'type', 'required', 'properties', 'additionalProperties', 'propertyNames', 'items',
        'minItems', 'maxItems', 'oneOf', 'anyOf', 'const', 'enum', 'pattern', 'minimum',
        'minLength', 'maxLength', 'description', 'title', 'default', '$comment', 'examples',
    ];

    public function __construct(private readonly array $document)
    {
    }

    /** @return list<string> the violations, empty when the value conforms */
    public function errors(array|bool $schema, mixed $value, string $path = '$'): array
    {
        if ($schema === true) {
            return [];
        }
        if ($schema === false) {
            return ["$path: nothing is allowed here"];
        }

        if (isset($schema['$ref'])) {
            return $this->errors($this->resolve($schema['$ref']), $value, $path);
        }

        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, self::KNOWN, true)) {
                return ["$path: JsonSchemaLite does not know the keyword '$keyword' — extend it or avoid it in the document"];
            }
        }

        $errors = [];

        if (isset($schema['type']) && !$this->hasType($value, (array) $schema['type'])) {
            $errors[] = "$path: expected " . implode('|', (array) $schema['type']) . ', got ' . $this->describe($value);

            return $errors; // nothing below makes sense on the wrong type
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "$path: expected the constant " . json_encode($schema['const']) . ', got ' . $this->describe($value);
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "$path: " . $this->describe($value) . ' is not one of ' . json_encode($schema['enum']);
        }
        if (isset($schema['pattern']) && is_string($value) && preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/u', $value) !== 1) {
            $errors[] = "$path: '" . $value . "' does not match " . $schema['pattern'];
        }
        if (isset($schema['minimum']) && is_numeric($value) && $value < $schema['minimum']) {
            $errors[] = "$path: $value is below " . $schema['minimum'];
        }
        if (isset($schema['minLength']) && is_string($value) && mb_strlen($value) < $schema['minLength']) {
            $errors[] = "$path: shorter than " . $schema['minLength'];
        }
        if (isset($schema['maxLength']) && is_string($value) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = "$path: longer than " . $schema['maxLength'];
        }

        foreach (['oneOf', 'anyOf'] as $combinator) {
            if (!isset($schema[$combinator])) {
                continue;
            }
            $passing = 0;
            $why = [];
            foreach ($schema[$combinator] as $i => $alternative) {
                $inner = $this->errors($alternative, $value, $path);
                if ($inner === []) {
                    $passing++;
                } else {
                    $why[] = "[$i] " . $inner[0];
                }
            }
            // ⚠ `oneOf` is held as "at least one" here, like anyOf. The document's oneOf branches
            // are told apart by a `const` (exists/role), so more than one passing would be the
            // document's own inconsistency, and the full validator in check-spec.py sees that.
            if ($passing === 0) {
                $errors[] = "$path: fits none of the $combinator alternatives — " . implode(' / ', $why);
            }
        }

        if ($this->isObject($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[] = "$path: the required key '$key' is missing";
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (array_key_exists($key, $value)) {
                    $errors = array_merge($errors, $this->errors($sub, $value[$key], "$path.$key"));
                }
            }
            if (isset($schema['propertyNames'])) {
                foreach (array_keys($value) as $key) {
                    $errors = array_merge($errors, $this->errors($schema['propertyNames'], (string) $key, "$path.<$key>"));
                }
            }
            if (array_key_exists('additionalProperties', $schema)) {
                foreach ($value as $key => $sub) {
                    if (isset($schema['properties'][$key])) {
                        continue;
                    }
                    if ($schema['additionalProperties'] === false) {
                        $errors[] = "$path: the key '$key' is not allowed here";
                    } elseif (is_array($schema['additionalProperties'])) {
                        $errors = array_merge($errors, $this->errors($schema['additionalProperties'], $sub, "$path.$key"));
                    }
                }
            }
        }

        if ($this->isList($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = "$path: fewer than " . $schema['minItems'] . ' items';
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "$path: more than " . $schema['maxItems'] . ' items';
            }
            if (isset($schema['items'])) {
                foreach ($value as $i => $item) {
                    $errors = array_merge($errors, $this->errors($schema['items'], $item, "$path\[$i]"));
                }
            }
        }

        return $errors;
    }

    /** `#/components/schemas/X` → that schema. */
    public function resolve(string $ref): array
    {
        if (!str_starts_with($ref, '#/')) {
            throw new \InvalidArgumentException("Only references into the document are supported, got $ref");
        }
        $node = $this->document;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                throw new \InvalidArgumentException("$ref points to nothing");
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function hasType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null' => $value === null,
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'string' => is_string($value),
                'object' => $this->isObject($value),
                'array' => $this->isList($value),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private function describe(mixed $value): string
    {
        return is_array($value)
            ? (array_is_list($value) ? 'a list' : 'an object')
            : json_encode($value);
    }
}
