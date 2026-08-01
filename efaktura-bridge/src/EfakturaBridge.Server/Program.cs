using System;
using System.IO;
using System.Net;
using System.Text;
using EfakturaBridge.Core;
using EfakturaBridge.Server;

string libraryPath = args.Length > 0 ? args[0] : @"C:\Windows\System32\eTPKCS11.dll";
IPkcs11SigningService signingService = new Pkcs11SigningService(libraryPath);
RequestRouter router = new RequestRouter(signingService);

using HttpListener listener = new HttpListener();
listener.Prefixes.Add("http://127.0.0.1:9847/");

try
{
    listener.Start();
}
catch (HttpListenerException ex)
{
    Console.WriteLine($"Не може да се стартува на порт 9847: {ex.Message}");
    Console.WriteLine("Веројатно мостот веќе работи. Притиснете Enter за затворање.");
    Console.ReadLine();
    return 1;
}

Console.WriteLine("Локалниот мост слуша на http://127.0.0.1:9847 (Ctrl+C за прекин)");

while (true)
{
    HttpListenerContext context;
    try
    {
        context = listener.GetContext();
    }
    catch (HttpListenerException)
    {
        break;
    }

    try
    {
        HttpListenerRequest req = context.Request;

        string? body = null;
        if (req.HasEntityBody)
        {
            using StreamReader reader = new StreamReader(req.InputStream, req.ContentEncoding);
            body = reader.ReadToEnd();
        }

        BridgeRequest bridgeRequest = new BridgeRequest
        {
            Method = req.HttpMethod,
            Path = req.Url?.AbsolutePath ?? "/",
            OriginHeader = req.Headers["Origin"],
            Body = body,
        };

        BridgeResponse response = router.Handle(bridgeRequest);

        HttpListenerResponse res = context.Response;
        res.StatusCode = response.StatusCode;
        res.ContentType = response.ContentType;
        foreach (System.Collections.Generic.KeyValuePair<string, string> header in response.Headers)
            res.Headers[header.Key] = header.Value;

        byte[] bytes = Encoding.UTF8.GetBytes(response.Body);
        res.ContentLength64 = bytes.Length;
        res.OutputStream.Write(bytes, 0, bytes.Length);
        res.OutputStream.Close();
    }
    catch (Exception ex)
    {
        Console.WriteLine($"Грешка при обработка на барање: {ex.Message}");
    }
}

return 0;
