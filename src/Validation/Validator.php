<?php

declare(strict_types=1);

namespace ExpressPHP\Validation;

use ExpressPHP\Database\Database;
use DateTimeImmutable;
use RuntimeException;

final class Validator
{
    public static function validate(
        array $input,
        array $rules,
        array $messages = [],
        array $jsonObjectPaths = [],
    ): array
    {
        $validated = [];
        $errors = self::unknownKeyErrors($input, array_map('strval', array_keys($rules)));

        foreach ($rules as $field => $fieldRules) {
            $errorCount = count($errors);
            $fieldRules = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);
            [$exists, $value] = self::get($input, (string)$field);
            $names = array_map(static fn(string $rule): string => strtolower(explode(':', $rule, 2)[0]), $fieldRules);

            if (!$exists) {
                if (in_array('optional', $names, true) || in_array('sometimes', $names, true)) {
                    continue;
                }
                if (in_array('required', $names, true)) {
                    self::addError($errors, $messages, (string)$field, 'required', []);
                }
                continue;
            }

            if (in_array('required', $names, true) && self::empty($value)) {
                self::addError($errors, $messages, (string)$field, 'required', []);
                continue;
            }

            if ($value === null && in_array('nullable', $names, true)) {
                self::set($validated, (string)$field, null);
                continue;
            }

            foreach ($fieldRules as $ruleDefinition) {
                [$rule, $parameters] = self::parseRule($ruleDefinition);

                if ($rule === 'sometimes' || $rule === 'optional') {
                    continue;
                }
                if ($rule === 'required' && (!$exists || self::empty($value))) {
                    self::addError($errors, $messages, (string)$field, $rule, $parameters);
                    continue;
                }
                if (!$exists) {
                    continue;
                }
                if ($rule === 'nullable' && $value === null) {
                    break;
                }

                [$valid, $normalized] = self::check(
                    $rule,
                    $value,
                    $parameters,
                    $input,
                    (string)$field,
                    $jsonObjectPaths,
                );
                if (!$valid) {
                    self::addError($errors, $messages, (string)$field, $rule, $parameters);
                    continue;
                }
                $value = $normalized;
            }

            if ($exists && count($errors) === $errorCount) {
                self::set($validated, (string)$field, $value);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    private static function check(
        string $rule,
        mixed $value,
        array $parameters,
        array $input,
        string $field,
        array $jsonObjectPaths,
    ): array
    {
        return match ($rule) {
            'required', 'nullable', 'sometimes', 'optional' => [true, $value],
            'string' => [is_string($value), $value],
            'integer' => self::integer($value),
            'numeric' => self::numeric($value),
            'boolean' => self::boolean($value),
            'array' => [is_array($value), $value],
            'object' => [is_array($value)
                && (!array_is_list($value) || in_array($field, $jsonObjectPaths, true)), $value],
            'list' => [is_array($value)
                && array_is_list($value) && !in_array($field, $jsonObjectPaths, true), $value],
            'email' => [is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false, $value],
            'url' => [is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false, $value],
            'min' => [self::size($value) >= (float)($parameters[0] ?? 0), $value],
            'max' => [self::size($value) <= (float)($parameters[0] ?? INF), $value],
            'between' => [self::size($value) >= (float)($parameters[0] ?? 0)
                && self::size($value) <= (float)($parameters[1] ?? INF), $value],
            'in' => [in_array((string)$value, $parameters, true), $value],
            'not_in' => [!in_array((string)$value, $parameters, true), $value],
            'same' => [self::comparison($input, $parameters[0] ?? '') === $value, $value],
            'different' => [self::comparison($input, $parameters[0] ?? '') !== $value, $value],
            'confirmed' => [self::comparison($input, $field . '_confirmation') === $value, $value],
            'regex' => [is_string($value) && isset($parameters[0]) && @preg_match($parameters[0], $value) === 1, $value],
            'date' => [is_string($value) && strtotime($value) !== false, $value],
            'date_format' => [self::validDateFormat($value, $parameters[0] ?? ''), $value],
            'unique' => [!self::databaseValueExists($value, $parameters), $value],
            'exists' => [self::databaseValueExists($value, $parameters), $value],
            default => throw new RuntimeException("Unknown validation rule [{$rule}]."),
        };
    }

    private static function integer(mixed $value): array
    {
        $valid = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
        return [$valid, $valid ? (int)$value : $value];
    }

    private static function numeric(mixed $value): array
    {
        if (!is_numeric($value)) {
            return [false, $value];
        }
        $normalized = str_contains((string)$value, '.') ? (float)$value : (int)$value;
        return [true, $normalized];
    }

    private static function boolean(mixed $value): array
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return [$normalized !== null, $normalized ?? $value];
    }

    private static function size(mixed $value): float
    {
        return match (true) {
            is_int($value), is_float($value) => (float)$value,
            is_string($value) => (float)strlen($value),
            is_array($value) => (float)count($value),
            default => -INF,
        };
    }

    private static function validDateFormat(mixed $value, string $format): bool
    {
        if (!is_string($value) || $format === '') {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $date !== false && $date->format($format) === $value;
    }

    private static function comparison(array $input, string $field): mixed
    {
        [, $value] = self::get($input, $field);
        return $value;
    }

    private static function parseRule(mixed $definition): array
    {
        if (!is_string($definition) || $definition === '') {
            throw new RuntimeException('Validation rules must be non-empty strings.');
        }
        [$name, $parameterText] = array_pad(explode(':', $definition, 2), 2, '');
        $parameters = $parameterText === '' ? [] : explode(',', $parameterText);
        return [strtolower($name), $parameters];
    }

    private static function addError(
        array  &$errors,
        array  $custom,
        string $field,
        string $rule,
        array  $parameters,
    ): void {
        $template = $custom[$field . '.' . $rule]
            ?? $custom[$field]
            ?? self::defaultMessage($rule);
        $message = str_replace(
            [':attribute', ':min', ':max', ':values', ':other'],
            [str_replace(['.', '_'], ' ', $field), $parameters[0] ?? '', $parameters[1] ?? '', implode(', ', $parameters), $parameters[0] ?? ''],
            $template,
        );
        $errors[] = $message;
    }

    private static function defaultMessage(string $rule): string
    {
        return match ($rule) {
            'required' => 'The :attribute field is required.',
            'string' => 'The :attribute field must be a string.',
            'integer' => 'The :attribute field must be an integer.',
            'numeric' => 'The :attribute field must be numeric.',
            'boolean' => 'The :attribute field must be true or false.',
            'array' => 'The :attribute field must be an array.',
            'object' => 'The :attribute field must be an object.',
            'list' => 'The :attribute field must be a list.',
            'email' => 'The :attribute field must contain a valid email address.',
            'url' => 'The :attribute field must contain a valid URL.',
            'min' => 'The :attribute field must be at least :min.',
            'max' => 'The :attribute field may not be greater than :min.',
            'between' => 'The :attribute field must be between :min and :max.',
            'in' => 'The :attribute field must be one of: :values.',
            'not_in' => 'The selected :attribute is invalid.',
            'same' => 'The :attribute field must match :other.',
            'different' => 'The :attribute field must differ from :other.',
            'confirmed' => 'The :attribute confirmation does not match.',
            'regex' => 'The :attribute field format is invalid.',
            'date' => 'The :attribute field must contain a valid date.',
            'date_format' => 'The :attribute field does not match the required format.',
            'unique' => 'The :attribute has already been taken.',
            'exists' => 'The selected :attribute does not exist.',
            default => 'The :attribute field is invalid.',
        };
    }

    private static function empty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private static function get(array $input, string $path): array
    {
        $value = $input;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }
        return [true, $value];
    }

    private static function set(array &$output, string $path, mixed $value): void
    {
        $target = &$output;
        foreach (explode('.', $path) as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;
    }

    private static function unknownKeyErrors(array $input, array $rules, string $prefix = ''): array
    {
        $errors = [];

        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            $exact = in_array($path, $rules, true);
            $hasChildren = false;

            foreach ($rules as $ruleField) {
                if (str_starts_with($ruleField, $path . '.')) {
                    $hasChildren = true;
                    break;
                }
            }

            if (!$exact && !$hasChildren) {
                $errors[] = "Invalid key {$path} in request.";
                continue;
            }

            if (!$exact && $hasChildren && is_array($value)) {
                $errors = [...$errors, ...self::unknownKeyErrors($value, $rules, $path)];
            }
        }

        return $errors;
    }

    private static function databaseValueExists(mixed $value, array $parameters): bool
    {
        $table = $parameters[0] ?? '';
        $column = $parameters[1] ?? '';
        $connection = $parameters[2] ?? null;

        if (!self::safeIdentifier($table) || !self::safeIdentifier($column)) {
            throw new RuntimeException('The unique and exists rules require a safe table and column name.');
        }

        $statement = Database::connection($connection ?: null)->prepare(
            "SELECT 1 FROM `{$table}` WHERE `{$column}` = :value LIMIT 1"
        );
        $statement->execute(['value' => $value]);
        return $statement->fetchColumn() !== false;
    }

    private static function safeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }
}
