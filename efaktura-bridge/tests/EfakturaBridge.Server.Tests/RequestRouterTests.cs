using System;
using EfakturaBridge.Core;
using EfakturaBridge.Server;
using Xunit;

namespace EfakturaBridge.Server.Tests;

public class RequestRouterTests
{
    private const string AllowedOrigin = "https://portal.financebuddy.mk";

    private static RequestRouter CreateRouter(FakeSigningService? fake = null)
        => new RequestRouter(fake ?? new FakeSigningService());

    [Fact]
    public void Health_NoOriginHeader_Returns200()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest { Method = "GET", Path = "/health" });

        Assert.Equal(200, response.StatusCode);
        Assert.Contains("\"status\":\"ok\"", response.Body);
    }

    [Fact]
    public void Health_AllowedOrigin_Returns200WithCorsHeader()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "GET",
            Path = "/health",
            OriginHeader = AllowedOrigin,
        });

        Assert.Equal(200, response.StatusCode);
        Assert.Equal(AllowedOrigin, response.Headers["Access-Control-Allow-Origin"]);
    }

    [Fact]
    public void Health_DisallowedOrigin_Returns403()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "GET",
            Path = "/health",
            OriginHeader = "https://evil.example",
        });

        Assert.Equal(403, response.StatusCode);
    }

    [Fact]
    public void Options_AllowedOrigin_Returns204WithCorsHeaders()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "OPTIONS",
            Path = "/sign",
            OriginHeader = AllowedOrigin,
        });

        Assert.Equal(204, response.StatusCode);
        Assert.Equal(AllowedOrigin, response.Headers["Access-Control-Allow-Origin"]);
    }

    [Fact]
    public void Certificate_ReturnsSigningServiceInfoAsJson()
    {
        FakeSigningService fake = new FakeSigningService();
        RequestRouter router = CreateRouter(fake);

        BridgeResponse response = router.Handle(new BridgeRequest { Method = "GET", Path = "/certificate" });

        Assert.Equal(200, response.StatusCode);
        Assert.Contains(fake.CertificateToReturn.SerialNumber, response.Body);
    }

    [Fact]
    public void Certificate_WhenSigningServiceThrows_Returns500()
    {
        FakeSigningService fake = new FakeSigningService { ThrowOnCertificateRead = true };
        RequestRouter router = CreateRouter(fake);

        BridgeResponse response = router.Handle(new BridgeRequest { Method = "GET", Path = "/certificate" });

        Assert.Equal(500, response.StatusCode);
    }

    [Fact]
    public void Sign_ValidBase64UrlData_ReturnsSignature()
    {
        FakeSigningService fake = new FakeSigningService();
        RequestRouter router = CreateRouter(fake);
        string encodedInput = Base64Url.Encode(new byte[] { 1, 2, 3 });

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "POST",
            Path = "/sign",
            Body = $"{{\"data\":\"{encodedInput}\"}}",
        });

        Assert.Equal(200, response.StatusCode);
        Assert.Equal(new byte[] { 1, 2, 3 }, fake.LastSignedData);
        string expectedSignature = Base64Url.Encode(fake.SignatureToReturn);
        Assert.Contains(expectedSignature, response.Body);
    }

    [Fact]
    public void Sign_MissingBody_Returns400()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest { Method = "POST", Path = "/sign", Body = null });

        Assert.Equal(400, response.StatusCode);
    }

    [Fact]
    public void Sign_InvalidJson_Returns400()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest { Method = "POST", Path = "/sign", Body = "not json" });

        Assert.Equal(400, response.StatusCode);
    }

    [Fact]
    public void Sign_InvalidBase64Url_Returns400()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "POST",
            Path = "/sign",
            Body = "{\"data\":\"not-valid-base64!!\"}",
        });

        Assert.Equal(400, response.StatusCode);
    }

    [Fact]
    public void UnknownRoute_Returns404()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest { Method = "GET", Path = "/nope" });

        Assert.Equal(404, response.StatusCode);
    }
}
