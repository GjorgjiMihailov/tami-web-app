using System;

namespace EfakturaBridge.Core;

public static class Base64Url
{
    public static string Encode(byte[] data)
    {
        return Convert.ToBase64String(data)
            .Replace('+', '-')
            .Replace('/', '_')
            .TrimEnd('=');
    }

    public static byte[] Decode(string value)
    {
        string base64 = value.Replace('-', '+').Replace('_', '/');
        int padding = (4 - base64.Length % 4) % 4;
        base64 += new string('=', padding);
        return Convert.FromBase64String(base64);
    }
}
