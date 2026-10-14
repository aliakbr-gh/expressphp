<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;

/**
 * Laravel-style validator.
 *
 *   $data = $request->validate([
 *       'name'     => 'required|string|max:100',
 *       'email'    => 'required|email|unique:users,email',
 *       'age'      => 'nullable|integer|between:18,120',
 *       'role'     => 'required|in:admin,editor',
 *       'password' => 'required|string|min:8|confirmed',
 *       'avatar'   => 'sometimes|image|mimes:jpg,png|max:2048',   // KB
 *       'code'     => ['required', 'regex:/^[A-Z]{3}-\d+$/'],     // use an array when a rule contains "|"
 *   ]);
 *
 * Rules: required, nullable, sometimes, string, integer, numeric, boolean, array, email, url,
 *        date, min, max, between, size, in, not_in, regex, alpha, alpha_num, alpha_dash,
 *        confirmed, same, different, unique, exists, file, image, mimes.
 *
 * Fields that are not required and empty skip their other rules. Dot keys ('address.city')
 * read nested values. Custom messages: ['email.unique' => '...', 'required' => '...'].
 */
final class Validator
{
    private const MESSAGES = [
        'required'   => 'The :attribute field is required.',
        'string'     => 'The :attribute field must be a string.',
        'integer'    => 'The :attribute field must be an integer.',
        'numeric'    => 'The :attribute field must be a number.',
        'boolean'    => 'The :attribute field must be true or false.',
        'array'      => 'The :attribute field must be an array.',
        'email'      => 'The :attribute field must be a valid email address.',
        'url'        => 'The :attribute field must be a valid URL.',
        'date'       => 'The :attribute field must be a valid date.',
        'in'         => 'The selected :attribute is invalid.',
        'not_in'     => 'The selected :attribute is invalid.',
        'regex'      => 'The :attribute field format is invalid.',
        'alpha'      => 'The :attribute field must only contain letters.',
        'alpha_num'  => 'The :attribute field must only contain letters and numbers.',
        'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
        'confirmed'  => 'The :attribute field confirmation does not match.',
        'same'       => 'The :attribute field must match :other.',
        'different'  => 'The :attribute field and :other must be different.',
        'unique'     => 'The :attribute has already been taken.',
        'exists'     => 'The selected :attribute is invalid.',
        'file'       => 'The :attribute field must be a file.',
        'image'      => 'The :attribute field must be an image.',
        'mimes'      => 'The :attribute field must be a file of type: :values.',
        'min'        => [
            'numeric' => 'The :attribute field must be at least :min.',
            'string'  => 'The :attribute field must be at least :min characters.',
            'array'   => 'The :attribute field must have at least :min items.',
            'file'    => 'The :attribute field must be at least :min kilobytes.',
        ],
        'max'        => [
            'numeric' => 'The :attribute field must not be greater than :max.',
            'string'  => 'The :attribute field must not be greater than :max characters.',
            'array'   => 'The :attribute field must not have more than :max items.',
            'file'    => 'The :attribute field must not be greater than :max kilobytes.',
        ],
        'between'    => [
            'numeric' => 'The :attribute field must be between :min and :max.',
            'string'  => 'The :attribute field must be between :min and :max characters.',
            'array'   => 'The :attribute field must have between :min and :max items.',
            'file'    => 'The :attribute field must be between :min and :max kilobytes.',
        ],
        'size'       => [
            'numeric' => 'The :attribute field must be :size.',
            'string'  => 'The :attribute field must be :size characters.',
            'array'   => 'The :attribute field must contain :size items.',
            'file'    => 'The :attribute field must be :size kilobytes.',
        ],
    ];

    private array $errors = [];
    private array $validated = [];
    private bool $ran = false;

    /**
     * @param array $data Input to validate (usually $request->all())
     * @param array $rules Field => 'rule|rule:param' string or list of rules
     * @param array $messages Custom messages keyed by 'field.rule' or 'rule'
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
    ) {
    }

    /** Shortcut for new Validator(...). */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    /** Runs validation (once) and returns true when there are errors. */
    public function fails(): bool
    {
        $this->run();
        return $this->errors !== [];
    }

    /** Opposite of fails(). */
    public function passes(): bool
    {
        return !$this->fails();
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        $this->run();
        return $this->errors;
    }

    /** Returns validated data or throws ValidationException. */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
        return $this->validated;
    }

    // ------------------------------------------------------------------ Engine

    /** Applies every rule to every field, stopping at the first failure per field. */
    private function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        foreach ($this->rules as $field => $rules) {
            $rules   = is_string($rules) ? explode('|', $rules) : $rules;
            $present = $this->has($field);
            $value   = $this->get($field);

            if (in_array('sometimes', $rules, true) && !$present) {
                continue;
            }

            if ($this->isEmpty($value)) {
                if (in_array('required', $rules, true)) {
                    $this->addError($field, 'required');
                } elseif ($present) {
                    $this->validated[$field] = $value;
                }
                continue;
            }

            // PHP already rejected the upload (e.g. bigger than upload_max_filesize): report its reason.
            if ($value instanceof UploadedFile && $value->error !== UPLOAD_ERR_OK) {
                $this->errors[$field][] = $value->errorMessage();
                continue;
            }

            $type = $this->sizeType($rules, $value);

            foreach ($rules as $rule) {
                [$name, $params] = $this->parseRule($rule);

                if (in_array($name, ['required', 'nullable', 'sometimes'], true)) {
                    continue;
                }

                if (!$this->check($name, $params, $field, $value, $type)) {
                    $this->addError($field, $name, $params, $type);
                    break; // one error per field is enough, like Laravel's "bail"
                }
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }
    }

    /** Returns true when $value passes a single rule. */
    private function check(string $rule, array $p, string $field, mixed $value, string $type): bool
    {
        return match ($rule) {
            'string'     => is_string($value),
            'integer'    => filter_var($value, FILTER_VALIDATE_INT) !== false && !is_bool($value),
            'numeric'    => is_numeric($value),
            'boolean'    => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'array'      => is_array($value),
            'email'      => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'        => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'date'       => is_string($value) && strtotime($value) !== false,
            'min'        => $this->size($value, $type) >= (float) $p[0],
            'max'        => $this->size($value, $type) <= (float) $p[0],
            'between'    => $this->size($value, $type) >= (float) $p[0] && $this->size($value, $type) <= (float) $p[1],
            'size'       => $this->size($value, $type) == (float) $p[0],
            'in'         => is_scalar($value) && in_array((string) $value, $p, true),
            'not_in'     => is_scalar($value) && !in_array((string) $value, $p, true),
            'regex'      => is_scalar($value) && preg_match($p[0], (string) $value) === 1,
            'alpha'      => is_string($value) && preg_match('/^[\pL\pM]+$/u', $value) === 1,
            'alpha_num'  => is_scalar($value) && preg_match('/^[\pL\pM\pN]+$/u', (string) $value) === 1,
            'alpha_dash' => is_scalar($value) && preg_match('/^[\pL\pM\pN_-]+$/u', (string) $value) === 1,
            'confirmed'  => $this->get($field . '_confirmation') === $value,
            'same'       => $this->get($p[0]) === $value,
            'different'  => $this->get($p[0]) !== $value,
            'unique'     => $this->isUnique($value, $field, $p),
            'exists'     => $this->exists($value, $field, $p),
            'file'       => $value instanceof UploadedFile && $value->isValid(),
            'image'      => $value instanceof UploadedFile && $value->isValid()
                            && in_array($value->mimeType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true),
            'mimes'      => $value instanceof UploadedFile && in_array($value->extension(), $p, true),
            default      => throw new InvalidArgumentException("Unknown validation rule [{$rule}]."),
        };
    }

    /** unique:table,column,exceptId,idColumn — e.g. unique:users,email,5 when updating user 5. */
    private function isUnique(mixed $value, string $field, array $p): bool
    {
        $table  = $this->identifier($p[0]);
        $column = $this->identifier($p[1] ?? $field);
        $sql    = "SELECT 1 FROM `{$table}` WHERE `{$column}` = ?";
        $params = [$value];

        if (isset($p[2]) && $p[2] !== '') {
            $sql     .= ' AND `' . $this->identifier($p[3] ?? 'id') . '` <> ?';
            $params[] = $p[2];
        }

        return Database::fetch($sql . ' LIMIT 1', $params) === null;
    }

    /** exists:table,column */
    private function exists(mixed $value, string $field, array $p): bool
    {
        $table  = $this->identifier($p[0]);
        $column = $this->identifier($p[1] ?? $field);

        return Database::fetch("SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1", [$value]) !== null;
    }

    /** Table/column names can't be bound as parameters, so only allow safe identifiers. */
    private function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Invalid identifier in validation rule: {$name}");
        }
        return $name;
    }

    // ------------------------------------------------------------------ Helpers

    /** Splits 'between:1,10' into ['between', ['1', '10']]. */
    private function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return [$rule, []];
        }

        [$name, $params] = explode(':', $rule, 2);

        // A regex may contain commas, so it is never split.
        return [$name, $name === 'regex' ? [$params] : explode(',', $params)];
    }

    /** How min/max/between/size measure the value. */
    private function sizeType(array $rules, mixed $value): string
    {
        return match (true) {
            $value instanceof UploadedFile                    => 'file',
            is_array($value)                                  => 'array',
            (bool) array_intersect(['integer', 'numeric'], $rules) && is_numeric($value) => 'numeric',
            default                                           => 'string',
        };
    }

    /** Measures a value for min/max/between/size: number value, string length, item count or file KB. */
    private function size(mixed $value, string $type): float
    {
        return match ($type) {
            'file'    => $value->size / 1024,
            'array'   => count($value),
            'numeric' => (float) $value,
            default   => (float) (is_scalar($value) ? (@iconv_strlen((string) $value, 'UTF-8') ?: strlen((string) $value)) : 0),
        };
    }

    /** null, empty string, whitespace-only string and empty array count as empty. */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    /** True when the field is present in the data (supports dot notation). */
    private function has(string $key): bool
    {
        return $this->get($key, $marker = new \stdClass()) !== $marker;
    }

    /** Reads a field from the data (supports dot notation). */
    private function get(string $key, mixed $default = null): mixed
    {
        $data = $this->data;
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }
        return $data;
    }

    /** Builds the message for a failed rule (custom message first) and records it. */
    private function addError(string $field, string $rule, array $params = [], string $type = 'string'): void
    {
        $message = $this->messages["{$field}.{$rule}"]
            ?? $this->messages[$rule]
            ?? self::MESSAGES[$rule]
            ?? "The :attribute field is invalid.";

        if (is_array($message)) {
            $message = $message[$type];
        }

        $this->errors[$field][] = strtr($message, [
            ':attribute' => str_replace(['_', '.'], ' ', $field),
            ':other'     => str_replace(['_', '.'], ' ', $params[0] ?? ''),
            ':min'       => $params[0] ?? '',
            ':max'       => $rule === 'between' ? ($params[1] ?? '') : ($params[0] ?? ''),
            ':size'      => $params[0] ?? '',
            ':values'    => implode(', ', $params),
        ]);
    }
}
