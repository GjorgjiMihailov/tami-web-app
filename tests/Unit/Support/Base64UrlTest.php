<?php

namespace Tests\Unit\Support;

use App\Support\Base64Url;
use PHPUnit\Framework\TestCase;

class Base64UrlTest extends TestCase
{
    public function test_encode_replaces_url_unsafe_characters_and_strips_padding(): void
    {
        $data = "\xFB\xFF\xBF";
        $standard = base64_encode($data);
        $this->assertStringContainsString('+', $standard);

        $result = Base64Url::encode($data);

        $this->assertStringNotContainsString('+', $result);
        $this->assertStringNotContainsString('/', $result);
        $this->assertStringNotContainsString('=', $result);
    }

    public function test_decode_is_inverse_of_encode(): void
    {
        $original = "\x01\x02\x03\xFA\xFB\xFC\xFD\xFE\xFF";

        $encoded = Base64Url::encode($original);
        $decoded = Base64Url::decode($encoded);

        $this->assertSame($original, $decoded);
    }

    public function test_decode_ascii_text_matches_known_value(): void
    {
        $this->assertSame('hello', Base64Url::decode('aGVsbG8'));
    }

    public function test_encode_empty_string_returns_empty_string(): void
    {
        $this->assertSame('', Base64Url::encode(''));
    }
}
