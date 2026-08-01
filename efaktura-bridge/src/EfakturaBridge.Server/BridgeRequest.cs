using System.Collections.Generic;

namespace EfakturaBridge.Server;

public sealed class BridgeRequest
{
    public required string Method { get; init; }
    public required string Path { get; init; }
    public string? OriginHeader { get; init; }
    public string? Body { get; init; }
}
