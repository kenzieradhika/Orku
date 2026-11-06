<?php

declare(strict_types=1);

namespace Orku\Http;

final class Headers
{
    /** @param array<string, string> $headers
     *  @return array<string, string>
     */
    public static function normalize(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException('Invalid HTTP header.');
            }
            $key = strtolower($name);
            if (isset($normalized[$key])) {
                throw new \InvalidArgumentException('Duplicate HTTP header: '.$key);
            }
            $normalized[$key] = trim($value, " \t");
        }

        return $normalized;
    }
}
