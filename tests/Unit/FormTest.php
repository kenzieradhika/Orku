<?php

declare(strict_types=1);

namespace Orku\Tests\Unit;

use Orku\Http\HttpException;
use Orku\Http\Request;
use Orku\Web\FormRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormTest extends TestCase
{
    private function request(string $body, string $type = 'application/x-www-form-urlencoded'): Request
    {
        return new Request('POST', '/contact', ['Content-Type' => $type], body: $body);
    }

    public function testParserPreservesNamesAndPlusUnicodeValues(): void
    {
        self::assertSame(['name' => 'José Doe', 'empty' => '', 'zero' => '0'], FormRequest::parse($this->request('name=Jos%C3%A9+Doe&empty=&zero=0'))->fields);
        self::assertSame([], FormRequest::parse($this->request(''))->fields);
        self::assertSame(['x' => 'a=b'], FormRequest::parse($this->request('x=a=b', 'application/x-www-form-urlencoded; charset=UTF-8'))->fields);
    }

    #[DataProvider('badBodies')]
    public function testParserRejectsMalformedAmbiguousOrOversizedForms(string $body, string $type, int $status): void
    {
        try {
            FormRequest::parse($this->request($body, $type));
            self::fail('Expected rejection.');
        } catch (HttpException $error) {
            self::assertSame($status, $error->status);
        }
    }

    public static function badBodies(): iterable
    {
        $type = 'application/x-www-form-urlencoded';
        foreach (['x=1&x=2', 'x[]=a', 'x[a]=b', 'x=%ff', 'x=%0', 'a.b=x', 'a+b=x', '=x', 'x', 'x=1&', '&x=1', 'x=%00'] as $body) {
            yield [$body, $type, 400];
        }
        yield ['x=1', 'multipart/form-data; boundary=x', 415];
        yield ['x=1', $type.'; charset=iso-8859-1', 415];
        yield [str_repeat('x', 65537), $type, 413];
        yield [implode('&', array_map(static fn ($i) => 'f'.$i.'=x', range(0, 100))), $type, 413];
    }

    public function testTypedValidationProducesValuesAndFieldErrors(): void
    {
        $rules = ['name' => ['type' => 'string', 'required' => true, 'min' => 2, 'max' => 10], 'age' => ['type' => 'number', 'min' => 0, 'max' => 120], 'email' => ['type' => 'email', 'required' => true], 'kind' => ['type' => 'string', 'allowed' => ['bug', 'idea']]];
        $good = FormRequest::parse($this->request('name=Jos%C3%A9&age=42&email=a%40example.com&kind=idea'))->validate($rules);
        self::assertTrue($good->valid());
        self::assertSame(42.0, $good->values['age']);
        self::assertSame('José', $good->values['name']);
        $bad = FormRequest::parse($this->request('name=x&age=1e999&email=bad&kind=other'))->validate($rules);
        self::assertFalse($bad->valid());
        self::assertSame(['name', 'age', 'email', 'kind'], array_keys($bad->errors));
        self::assertSame([], $bad->values);
        $missing = FormRequest::parse($this->request(''))->validate($rules);
        self::assertArrayHasKey('name', $missing->errors);
        self::assertArrayHasKey('email', $missing->errors);
    }

    public function testUnicodeLengthAndZeroRequiredValue(): void
    {
        $result = FormRequest::parse($this->request('name=%C3%A9%C3%A9&zero=0'))->validate(['name' => ['type' => 'string', 'min' => 2, 'max' => 2], 'zero' => ['type' => 'number', 'required' => true]]);
        self::assertTrue($result->valid());
        self::assertSame(0.0, $result->values['zero']);
    }
}
