<?php

declare(strict_types=1);

namespace Orku\Web;

use Orku\Http\HttpException;
use Orku\Http\Request;

final readonly class FormRequest
{
    /** @param array<string, string> $fields */
    private function __construct(public array $fields)
    {
    }

    public static function parse(Request $request, int $maxBytes = 65536, int $maxFields = 100): self
    {
        if ($maxBytes < 0 || $maxFields < 1) {
            throw new \InvalidArgumentException('Invalid form limits.');
        }
        if (strlen($request->body) > $maxBytes) {
            throw new HttpException(413);
        }
        if (!preg_match('~^application/x-www-form-urlencoded(?:\s*;\s*charset\s*=\s*(?:UTF-8|"UTF-8"))?\s*$~iD', $request->header('content-type') ?? '')) {
            throw new HttpException(415);
        }
        if ($request->body === '') {
            return new self([]);
        }
        $pairs = explode('&', $request->body);
        if (count($pairs) > $maxFields) {
            throw new HttpException(413);
        }
        $fields = [];
        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=') || preg_match('/%(?![0-9a-f]{2})/i', $pair)) {
                throw new HttpException(400);
            }
            [$key, $value] = array_map(urldecode(...), explode('=', $pair, 2));
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{0,99}$/D', $key) || array_key_exists($key, $fields) || !preg_match('//u', $value) || str_contains($value, "\0")) {
                throw new HttpException(400);
            }
            $fields[$key] = $value;
        }

        return new self($fields);
    }

    /** @param array<string, array{type: 'string'|'number'|'email', required?: bool, min?: int|float, max?: int|float, allowed?: list<string>}> $rules */
    public function validate(array $rules): ValidationResult
    {
        $values = [];
        $errors = [];
        foreach ($rules as $name => $rule) {
            $value = trim($this->fields[$name] ?? '');
            if ($value === '') {
                if ($rule['required'] ?? false) {
                    $errors[$name][] = 'This field is required.';
                }
                continue;
            }
            $typed = $value;
            if ($rule['type'] === 'number') {
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    $errors[$name][] = 'Enter a finite number.';
                    continue;
                }
                $typed = (float) $value;
                $size = $typed;
            } else {
                $size = preg_match_all('/./us', $value);
                if ($rule['type'] === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[$name][] = 'Enter a valid email address.';
                }
            }
            if (isset($rule['min']) && $size < $rule['min']) {
                $errors[$name][] = 'Value is below the minimum of '.$rule['min'].'.';
            }
            if (isset($rule['max']) && $size > $rule['max']) {
                $errors[$name][] = 'Value exceeds the maximum of '.$rule['max'].'.';
            }
            if (isset($rule['allowed']) && !in_array($value, $rule['allowed'], true)) {
                $errors[$name][] = 'Choose an allowed value.';
            }
            if (!isset($errors[$name])) {
                $values[$name] = $typed;
            }
        }

        return new ValidationResult($values, $errors);
    }
}
