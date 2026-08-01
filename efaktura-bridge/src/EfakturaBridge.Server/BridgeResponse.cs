using System.Collections.Generic;

namespace EfakturaBridge.Server;

public sealed class BridgeResponse
{
    public required int StatusCode { get; init; }
    public required string Body { get; init; }
    public string ContentType { get; init; } = "application/json";
    public Dictionary<string, string> Headers { get; init; } = new();
}
