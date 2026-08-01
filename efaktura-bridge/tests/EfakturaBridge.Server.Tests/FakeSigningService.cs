using System;
using EfakturaBridge.Core;

namespace EfakturaBridge.Server.Tests;

public sealed class FakeSigningService : IPkcs11SigningService
{
    public bool ThrowOnCertificateRead { get; set; }
    public bool ThrowOnSign { get; set; }
    public CertificateInfo CertificateToReturn { get; set; } = new CertificateInfo(
        "1A2B3C", "CN=Test Company", DateTime.UtcNow.AddYears(-1), DateTime.UtcNow.AddYears(1), "ZmFrZS1jZXJ0");
    public byte[] SignatureToReturn { get; set; } = { 9, 9, 9 };
    public byte[]? LastSignedData { get; private set; }

    public CertificateInfo GetCertificateInfo()
    {
        if (ThrowOnCertificateRead)
            throw new InvalidOperationException("Нема приклучен токен.");
        return CertificateToReturn;
    }

    public byte[] Sign(byte[] data)
    {
        if (ThrowOnSign)
            throw new InvalidOperationException("Погрешен PIN.");
        LastSignedData = data;
        return SignatureToReturn;
    }
}
