using System;
using System.Collections.Generic;
using System.Text.Json;
using EfakturaBridge.Core;

namespace EfakturaBridge.Server;

public sealed class RequestRouter
{
    private const string AllowedOrigin = "https://portal.financebuddy.mk";
    private static readonly string[] AllowedHosts = { "127.0.0.1:9847", "localhost:9847" };

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
    };

    private readonly IPkcs11SigningService _signingService;

    public RequestRouter(IPkcs11SigningService signingService)
    {
        _signingService = signingService;
    }

    public BridgeResponse Handle(BridgeRequest request)
    {
        if (request.HostHeader is null || Array.IndexOf(AllowedHosts, request.HostHeader) < 0)
            return new BridgeResponse { StatusCode = 403, Body = """{"error":"host_not_allowed"}""" };

        Dictionary<string, string>? corsHeaders = BuildCorsHeaders(request.OriginHeader);
        if (corsHeaders is null)
            return new BridgeResponse { StatusCode = 403, Body = """{"error":"origin_not_allowed"}""" };

        if (request.Method == "OPTIONS")
            return new BridgeResponse { StatusCode = 204, Body = "", Headers = corsHeaders };

        BridgeResponse response = (request.Method, request.Path) switch
        {
            ("GET", "/health") => HandleHealth(),
            ("GET", "/certificate") => HandleCertificate(),
            ("POST", "/sign") => HandleSign(request.Body),
            _ => new BridgeResponse { StatusCode = 404, Body = """{"error":"not_found"}""" },
        };

        foreach (KeyValuePair<string, string> header in corsHeaders)
            response.Headers[header.Key] = header.Value;

        return response;
    }

    private static Dictionary<string, string>? BuildCorsHeaders(string? origin)
    {
        if (origin is null)
            return new Dictionary<string, string>();
        if (origin != AllowedOrigin)
            return null;

        return new Dictionary<string, string>
        {
            ["Access-Control-Allow-Origin"] = AllowedOrigin,
            ["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS",
            ["Access-Control-Allow-Headers"] = "Content-Type",
        };
    }

    private static BridgeResponse HandleHealth()
        => new BridgeResponse { StatusCode = 200, Body = """{"status":"ok"}""" };

    private BridgeResponse HandleCertificate()
    {
        try
        {
            CertificateInfo info = _signingService.GetCertificateInfo();
            return new BridgeResponse { StatusCode = 200, Body = JsonSerializer.Serialize(info, JsonOptions) };
        }
        catch (Exception ex)
        {
            return new BridgeResponse
            {
                StatusCode = 500,
                Body = JsonSerializer.Serialize(new { error = "token_error", message = ex.Message }),
            };
        }
    }

    private BridgeResponse HandleSign(string? body)
    {
        if (string.IsNullOrEmpty(body))
            return new BridgeResponse { StatusCode = 400, Body = """{"error":"missing_body"}""" };

        SignRequest? signRequest;
        try
        {
            signRequest = JsonSerializer.Deserialize<SignRequest>(body, JsonOptions);
        }
        catch (JsonException)
        {
            return new BridgeResponse { StatusCode = 400, Body = """{"error":"invalid_json"}""" };
        }

        if (signRequest is null || string.IsNullOrEmpty(signRequest.Data))
            return new BridgeResponse { StatusCode = 400, Body = """{"error":"missing_data"}""" };

        byte[] dataToSign;
        try
        {
            dataToSign = Base64Url.Decode(signRequest.Data);
        }
        catch (FormatException)
        {
            return new BridgeResponse { StatusCode = 400, Body = """{"error":"invalid_base64url"}""" };
        }

        try
        {
            byte[] signature = _signingService.Sign(dataToSign);
            string json = JsonSerializer.Serialize(new SignResponse(Base64Url.Encode(signature)), JsonOptions);
            return new BridgeResponse { StatusCode = 200, Body = json };
        }
        catch (Exception ex)
        {
            return new BridgeResponse
            {
                StatusCode = 500,
                Body = JsonSerializer.Serialize(new { error = "sign_failed", message = ex.Message }),
            };
        }
    }
}
