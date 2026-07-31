using System;
using System.Text;
using EfakturaBridge.Core;
using Xunit;

namespace EfakturaBridge.Core.Tests;

public class Base64UrlTests
{
    [Fact]
    public void Encode_EmptyArray_ReturnsEmptyString()
    {
        Assert.Equal("", Base64Url.Encode(Array.Empty<byte>()));
    }

    [Fact]
    public void Encode_ReplacesUrlUnsafeCharactersAndStripsPadding()
    {
        // Bytes chosen so standard Base64 is known to contain '+' and requires padding.
        byte[] data = { 0xFB, 0xFF, 0xBF };
        string standard = Convert.ToBase64String(data);
        Assert.Contains("+", standard);

        string result = Base64Url.Encode(data);

        Assert.DoesNotContain("+", result);
        Assert.DoesNotContain("/", result);
        Assert.DoesNotContain("=", result);
    }

    [Fact]
    public void Decode_IsInverseOfEncode()
    {
        byte[] original = { 1, 2, 3, 250, 251, 252, 253, 254, 255 };
        string encoded = Base64Url.Encode(original);

        byte[] decoded = Base64Url.Decode(encoded);

        Assert.Equal(original, decoded);
    }

    [Fact]
    public void Decode_AsciiText_MatchesKnownValue()
    {
        byte[] result = Base64Url.Decode("aGVsbG8");
        Assert.Equal("hello", Encoding.UTF8.GetString(result));
    }
}
