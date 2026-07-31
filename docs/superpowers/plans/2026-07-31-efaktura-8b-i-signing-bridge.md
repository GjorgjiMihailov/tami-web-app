# е-Фактура 8b-i: локален .NET мост за потпишување (спајк → целосен HTTP-сервис) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and prove, end-to-end, a standalone local .NET bridge application that talks to the SafeNet PKCS#11 hardware-token driver, reads the token's public certificate, and signs arbitrary byte input — exposed over a fixed local HTTP port, tested entirely via HTTP calls, with zero Laravel involvement.

**Architecture:** A .NET 8 solution with two layers: `EfakturaBridge.Core` (PKCS#11 talk-to-the-token logic, hidden behind an `IPkcs11SigningService` interface so the HTTP layer is unit-testable without hardware) and `EfakturaBridge.Server` (a thin `HttpListener`-based web service exposing `/health`, `/certificate`, `/sign`, with Origin/CORS enforcement so only `https://portal.financebuddy.mk` can call it from a browser). A throwaway `EfakturaBridge.Spike` console app comes first to prove basic PKCS#11 connectivity works at all before any of the reusable/production code is built. Final output is a single portable self-contained `.exe`.

**Tech Stack:** .NET 8 (C#), `Pkcs11Interop` NuGet package for PKCS#11, `System.Net.HttpListener` (no ASP.NET Core/Kestrel), `System.Text.Json`, xUnit for unit tests.

## Global Constraints

- Target framework: **.NET 8** (LTS) — matches the .NET 8.0.12 runtime already confirmed installed on this machine.
- PKCS#11 interop library: **`Pkcs11Interop`** NuGet package — decided in the design doc (`docs/superpowers/specs/2026-07-31-efaktura-8b-local-signing-bridge-design.md`, commit `5f36dca`).
- HTTP layer: **`System.Net.HttpListener`**, not ASP.NET Core/Kestrel — per the design's "no installer, no separate runtime, single portable `.exe`" requirement.
- Fixed listen address: **`http://127.0.0.1:9847/`** — exact port from the design doc.
- Only origin allowed to call the bridge from a browser: **`https://portal.financebuddy.mk`** (exact string match on the `Origin` header) — per design §А.
- The bridge is **"dumb"**: no invoice/company/УЈП-format logic, no JWS assembly. It only signs opaque byte input it's handed and returns raw signature bytes, base64url-encoded. All JWS/business logic stays in Laravel — that's a separate plan (8b-ii), not this one.
- PIN handling: when the token reports `CKF_PROTECTED_AUTHENTICATION_PATH` (i.e. `ITokenInfo.ProtectedAuthenticationPath == true`), the PIN dialog is SafeNet's own — the app must call `Login` with a `null` PIN and never prompt for or hold a PIN string. Only fall back to an in-app PIN prompt if that flag is `false`. This must be checked at runtime, not assumed.
- Distribution: **no installer, no auto-start, no auto-update** — final artifact is a single self-contained portable `.exe` (`dotnet publish ... -p:PublishSingleFile=true`). Uploading it to the tami-web-app Settings download page is out of scope for this plan (belongs to plan 8b-ii).
- SafeNet Authentication Client's PKCS#11 module is **confirmed present on this machine** at `C:\Windows\System32\eTPKCS11.dll` (64-bit) — use as the default library path, but keep it a configurable argument since other firm PCs may install to a different path.
- This machine has **no .NET SDK installed** (only the runtime) — Task 1 Step 1 installs it via `winget`. That step downloads and installs software and needs the user's go-ahead at execution time per the standing safety protocol — do not skip asking just because it's written down here.
- Hardware-dependent steps (reading the real certificate, producing a real signature) **cannot be exercised by an automated test** — they require the physical USB token plugged in and a real interactive terminal (for the SafeNet PIN dialog / fallback PIN prompt), so those specific steps must be run by the user themselves, with output pasted back — same pattern already established in this project for droplet-only actions.

---

## File Structure

```
tami-web-app/
  efaktura-bridge/                                    ← new, self-contained .NET solution
    .gitignore
    EfakturaBridge.sln
    src/
      EfakturaBridge.Core/
        EfakturaBridge.Core.csproj
        Base64Url.cs                                  ← Task 1
        CertificateInfo.cs                             ← Task 3
        IPkcs11SigningService.cs                        ← Task 3
        Pkcs11SigningService.cs                         ← Task 3
      EfakturaBridge.Spike/
        EfakturaBridge.Spike.csproj                     ← Task 2
        Program.cs                                      ← Task 2
      EfakturaBridge.Server/
        EfakturaBridge.Server.csproj                     ← Task 4
        BridgeRequest.cs                                 ← Task 4
        BridgeResponse.cs                                ← Task 4
        SignRequest.cs                                   ← Task 4
        SignResponse.cs                                  ← Task 4
        RequestRouter.cs                                 ← Task 4
        Program.cs                                       ← Task 5
    tests/
      EfakturaBridge.Core.Tests/
        EfakturaBridge.Core.Tests.csproj
        Base64UrlTests.cs                                ← Task 1
      EfakturaBridge.Server.Tests/
        EfakturaBridge.Server.Tests.csproj
        FakeSigningService.cs                            ← Task 4
        RequestRouterTests.cs                             ← Task 4
```

---

### Task 1: Install .NET SDK, scaffold the solution, and TDD the `Base64Url` helper

**Files:**
- Create: `efaktura-bridge/.gitignore`
- Create: `efaktura-bridge/EfakturaBridge.sln`
- Create: `efaktura-bridge/src/EfakturaBridge.Core/EfakturaBridge.Core.csproj`
- Create: `efaktura-bridge/src/EfakturaBridge.Core/Base64Url.cs`
- Create: `efaktura-bridge/tests/EfakturaBridge.Core.Tests/EfakturaBridge.Core.Tests.csproj`
- Create: `efaktura-bridge/tests/EfakturaBridge.Core.Tests/Base64UrlTests.cs`

**Interfaces:**
- Produces: `EfakturaBridge.Core.Base64Url.Encode(byte[] data) -> string` and `EfakturaBridge.Core.Base64Url.Decode(string value) -> byte[]` — RFC 4648 base64url (no padding). Used by Task 2 (spike output), Task 4 (`/sign` request/response bodies).

- [ ] **Step 1: Install the .NET 8 SDK**

Run:
```powershell
winget install Microsoft.DotNet.SDK.8 --silent --accept-package-agreements --accept-source-agreements
```
Expected: winget reports a successful install (or "already installed" if it's already there).

- [ ] **Step 2: Verify the SDK is on PATH**

Run:
```powershell
dotnet --list-sdks
```
Expected: at least one line starting with `8.0.` (e.g. `8.0.4xx [C:\Program Files\dotnet\sdk]`). If nothing is printed, close and reopen the terminal (PATH was updated by the installer) and re-run.

- [ ] **Step 3: Scaffold the solution and the Core project**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app"
mkdir efaktura-bridge
cd efaktura-bridge
dotnet new sln -n EfakturaBridge
dotnet new classlib -n EfakturaBridge.Core -o src\EfakturaBridge.Core -f net8.0
dotnet new xunit -n EfakturaBridge.Core.Tests -o tests\EfakturaBridge.Core.Tests -f net8.0
dotnet sln add src\EfakturaBridge.Core\EfakturaBridge.Core.csproj
dotnet sln add tests\EfakturaBridge.Core.Tests\EfakturaBridge.Core.Tests.csproj
dotnet add tests\EfakturaBridge.Core.Tests\EfakturaBridge.Core.Tests.csproj reference src\EfakturaBridge.Core\EfakturaBridge.Core.csproj
```
Expected: no errors; `dotnet sln list` shows both projects.

Delete the placeholder `src\EfakturaBridge.Core\Class1.cs` file that `dotnet new classlib` generates — it isn't used.

- [ ] **Step 4: Add a `.gitignore` for build output**

```gitignore
bin/
obj/
publish/
```
Save as `efaktura-bridge/.gitignore`.

- [ ] **Step 5: Write the failing tests for `Base64Url`**

```csharp
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
```

Save as `efaktura-bridge/tests/EfakturaBridge.Core.Tests/Base64UrlTests.cs`.

- [ ] **Step 6: Run the tests and confirm they fail to compile**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet test tests\EfakturaBridge.Core.Tests
```
Expected: build FAILS with `error CS0246: The type or namespace name 'Base64Url' could not be found`.

- [ ] **Step 7: Implement `Base64Url`**

```csharp
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
```

Save as `efaktura-bridge/src/EfakturaBridge.Core/Base64Url.cs`.

- [ ] **Step 8: Run the tests and confirm they pass**

Run:
```powershell
dotnet test tests\EfakturaBridge.Core.Tests
```
Expected: `Passed! - Failed: 0, Passed: 4`.

- [ ] **Step 9: Commit**

```bash
git add efaktura-bridge/
git commit -m "feat(efaktura-bridge): scaffold .NET solution with Base64Url helper"
```

---

### Task 2: Spike — prove the SafeNet PKCS#11 driver can be talked to and can sign

**Files:**
- Create: `efaktura-bridge/src/EfakturaBridge.Spike/EfakturaBridge.Spike.csproj`
- Create: `efaktura-bridge/src/EfakturaBridge.Spike/Program.cs`

**Interfaces:**
- Consumes: `EfakturaBridge.Core.Base64Url.Encode(byte[]) -> string` (Task 1).
- Produces: no reusable code — this is a throwaway validation program. Its result (does `ProtectedAuthenticationPath` come back `true` or `false` for this token?) informs how `Pkcs11SigningService.Sign` in Task 3 must behave, but Task 3's code already handles both cases at runtime, so nothing here needs to be re-consumed by name.

This task has no automated test — it validates against **real physical hardware** (the SafeNet USB token) which cannot be part of a CI/unit-test run. The steps below must be run by the user, with the token physically plugged in, in a real interactive terminal (the SafeNet PIN dialog needs a real desktop session).

- [ ] **Step 1: Create the spike console project**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet new console -n EfakturaBridge.Spike -o src\EfakturaBridge.Spike -f net8.0
dotnet sln add src\EfakturaBridge.Spike\EfakturaBridge.Spike.csproj
dotnet add src\EfakturaBridge.Spike\EfakturaBridge.Spike.csproj reference src\EfakturaBridge.Core\EfakturaBridge.Core.csproj
dotnet add src\EfakturaBridge.Spike\EfakturaBridge.Spike.csproj package Pkcs11Interop
```
Expected: no errors; `Pkcs11Interop` appears in `EfakturaBridge.Spike.csproj`'s `<ItemGroup>` as a `<PackageReference>`.

- [ ] **Step 2: Write the spike program**

```csharp
using System;
using System.Collections.Generic;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using EfakturaBridge.Core;
using Net.Pkcs11Interop.Common;
using Net.Pkcs11Interop.HighLevelAPI;

string libraryPath = args.Length > 0 ? args[0] : @"C:\Windows\System32\eTPKCS11.dll";
Console.WriteLine($"Користам PKCS#11 модул: {libraryPath}");

Pkcs11InteropFactories factories = new Pkcs11InteropFactories();

using IPkcs11Library pkcs11Library = factories.Pkcs11LibraryFactory.LoadPkcs11Library(
    factories, libraryPath, AppType.MultiThreaded);

List<ISlot> slots = pkcs11Library.GetSlotList(SlotsType.WithTokenPresent);
if (slots.Count == 0)
{
    Console.WriteLine("ГРЕШКА: нема приклучен токен. Приклучете го USB токенот и обидете се повторно.");
    return 1;
}

ISlot slot = slots[0];
ITokenInfo tokenInfo = slot.GetTokenInfo();
Console.WriteLine($"Најден токен: {tokenInfo.Label}, сериски број: {tokenInfo.SerialNumber}");
Console.WriteLine($"ProtectedAuthenticationPath: {tokenInfo.ProtectedAuthenticationPath}");

using ISession session = slot.OpenSession(SessionType.ReadWrite);

if (tokenInfo.ProtectedAuthenticationPath)
{
    Console.WriteLine("Токенот поддржува заштитен PIN-влез — очекувајте сопствен прозорец од SafeNet.");
    session.Login(CKU.CKU_USER, null);
}
else
{
    Console.Write("Внесете PIN за токенот: ");
    string? pin = Console.ReadLine();
    session.Login(CKU.CKU_USER, pin);
}

List<IObjectAttribute> certSearchAttrs = new List<IObjectAttribute>
{
    factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_CERTIFICATE)
};
List<IObjectHandle> certObjects = session.FindAllObjects(certSearchAttrs);
if (certObjects.Count == 0)
{
    Console.WriteLine("ГРЕШКА: не е најден сертификат на токенот.");
    session.Logout();
    return 1;
}

List<IObjectAttribute> certValues = session.GetAttributeValue(certObjects[0], new List<CKA> { CKA.CKA_VALUE });
byte[] certDer = certValues[0].GetValueAsByteArray();
Console.WriteLine($"Сертификат прочитан ({certDer.Length} бајти).");

List<IObjectAttribute> keySearchAttrs = new List<IObjectAttribute>
{
    factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_PRIVATE_KEY)
};
List<IObjectHandle> keyObjects = session.FindAllObjects(keySearchAttrs);
if (keyObjects.Count == 0)
{
    Console.WriteLine("ГРЕШКА: не е најден приватен клуч на токенот.");
    session.Logout();
    return 1;
}

IMechanism mechanism = factories.MechanismFactory.Create(CKM.CKM_SHA256_RSA_PKCS);
byte[] testData = Encoding.UTF8.GetBytes("efaktura-bridge-spike-test");
byte[] signature = session.Sign(mechanism, keyObjects[0], testData);

session.Logout();

Console.WriteLine($"Потпис (base64url, {signature.Length} бајти): {Base64Url.Encode(signature)}");

using X509Certificate2 certificate = new X509Certificate2(certDer);
using RSA? publicKey = certificate.GetRSAPublicKey();
bool valid = publicKey is not null &&
    publicKey.VerifyData(testData, signature, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);

Console.WriteLine(valid
    ? "ПОТВРДЕНО: потписот е валиден против јавниот клуч од сертификатот."
    : "ГРЕШКА: потписот НЕ е валиден.");

return valid ? 0 : 1;
```

Save as `efaktura-bridge/src/EfakturaBridge.Spike/Program.cs` (replace the generated placeholder content).

- [ ] **Step 3: Build the spike**

Run:
```powershell
dotnet build src\EfakturaBridge.Spike
```
Expected: `Build succeeded.` If `Pkcs11Interop`'s actual API differs slightly from what's above (interface/method names occasionally shift between versions), adjust the calls to match while keeping the same sequence: load library → list slots → check `ProtectedAuthenticationPath` → login → find certificate object → find private key object → sign → verify locally.

- [ ] **Step 4: Run the spike with the real token — THIS STEP IS FOR THE USER**

Plug in the SafeNet USB token, then run:
```powershell
dotnet run --project src\EfakturaBridge.Spike
```
When SafeNet's own PIN dialog appears (or the console prompts for a PIN if `ProtectedAuthenticationPath` is `false`), enter the token PIN.

Expected: the program prints the token label and serial number, the `ProtectedAuthenticationPath` value, the certificate size, a base64url signature, and ends with `ПОТВРДЕНО: потписот е валиден против јавниот клуч од сертификатот.`

Paste the full console output back so it can be confirmed before continuing to Task 3. If it fails, capture the exact error message — it usually means either the library path is wrong (check `efaktura-bridge/src/EfakturaBridge.Spike` step 1's `libraryPath` default against where SafeNet Authentication Client is actually installed on this machine) or the token wasn't detected (re-seat the USB token).

- [ ] **Step 5: Commit**

```bash
git add efaktura-bridge/
git commit -m "feat(efaktura-bridge): spike proving PKCS#11 connect+sign works against the SafeNet token"
```

---

### Task 3: `Pkcs11SigningService` — reusable, stateless PKCS#11 signing behind an interface

**Files:**
- Create: `efaktura-bridge/src/EfakturaBridge.Core/CertificateInfo.cs`
- Create: `efaktura-bridge/src/EfakturaBridge.Core/IPkcs11SigningService.cs`
- Create: `efaktura-bridge/src/EfakturaBridge.Core/Pkcs11SigningService.cs`
- Modify: `efaktura-bridge/src/EfakturaBridge.Core/EfakturaBridge.Core.csproj` (add `Pkcs11Interop` package reference)

**Interfaces:**
- Consumes: the confirmed-working sequence from Task 2's spike (load library → list slots → check `ProtectedAuthenticationPath` → login → find cert/key → sign).
- Produces: `EfakturaBridge.Core.CertificateInfo` record (`SerialNumber`, `SubjectName`, `NotBefore`, `NotAfter`, `CertificateBase64`), `EfakturaBridge.Core.IPkcs11SigningService` interface (`CertificateInfo GetCertificateInfo()`, `byte[] Sign(byte[] data)`), and `EfakturaBridge.Core.Pkcs11SigningService` implementing it. Consumed by Task 4's `RequestRouter`.

This service opens a fresh PKCS#11 session on every call rather than holding one open — the bridge is a single-user local tool called occasionally, so per-call sessions avoid any stale-session-state bugs and keep the code simple (no session lifecycle to manage between HTTP requests).

There's no meaningful way to unit test the real PKCS#11 calls without hardware (that's what Task 2 already validated manually), so this task has no `dotnet test` step — correctness is verified in Task 5's live check against the real token, reusing this exact class.

- [ ] **Step 1: Add the `Pkcs11Interop` package to Core**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet add src\EfakturaBridge.Core\EfakturaBridge.Core.csproj package Pkcs11Interop
```
Expected: no errors.

- [ ] **Step 2: Write `CertificateInfo`**

```csharp
using System;

namespace EfakturaBridge.Core;

public sealed record CertificateInfo(
    string SerialNumber,
    string SubjectName,
    DateTime NotBefore,
    DateTime NotAfter,
    string CertificateBase64);
```

Save as `efaktura-bridge/src/EfakturaBridge.Core/CertificateInfo.cs`.

- [ ] **Step 3: Write `IPkcs11SigningService`**

```csharp
namespace EfakturaBridge.Core;

public interface IPkcs11SigningService
{
    CertificateInfo GetCertificateInfo();
    byte[] Sign(byte[] data);
}
```

Save as `efaktura-bridge/src/EfakturaBridge.Core/IPkcs11SigningService.cs`.

- [ ] **Step 4: Write `Pkcs11SigningService`**

```csharp
using System;
using System.Collections.Generic;
using System.Security.Cryptography.X509Certificates;
using Net.Pkcs11Interop.Common;
using Net.Pkcs11Interop.HighLevelAPI;

namespace EfakturaBridge.Core;

public sealed class Pkcs11SigningService : IPkcs11SigningService
{
    private readonly string _libraryPath;
    private readonly Pkcs11InteropFactories _factories = new();

    public Pkcs11SigningService(string libraryPath)
    {
        _libraryPath = libraryPath;
    }

    public CertificateInfo GetCertificateInfo()
    {
        using IPkcs11Library library = LoadLibrary();
        ISlot slot = GetFirstSlotWithToken(library);
        using ISession session = slot.OpenSession(SessionType.ReadOnly);
        byte[] certDer = ReadCertificateDer(session);

        using X509Certificate2 cert = new X509Certificate2(certDer);
        return new CertificateInfo(
            cert.SerialNumber,
            cert.Subject,
            cert.NotBefore,
            cert.NotAfter,
            Convert.ToBase64String(certDer));
    }

    public byte[] Sign(byte[] data)
    {
        using IPkcs11Library library = LoadLibrary();
        ISlot slot = GetFirstSlotWithToken(library);
        ITokenInfo tokenInfo = slot.GetTokenInfo();
        using ISession session = slot.OpenSession(SessionType.ReadWrite);

        if (tokenInfo.ProtectedAuthenticationPath)
        {
            session.Login(CKU.CKU_USER, null);
        }
        else
        {
            Console.Write("Внесете PIN за токенот: ");
            string? pin = Console.ReadLine();
            session.Login(CKU.CKU_USER, pin);
        }

        try
        {
            IObjectHandle privateKey = FindPrivateKey(session);
            IMechanism mechanism = _factories.MechanismFactory.Create(CKM.CKM_SHA256_RSA_PKCS);
            return session.Sign(mechanism, privateKey, data);
        }
        finally
        {
            session.Logout();
        }
    }

    private IPkcs11Library LoadLibrary()
    {
        return _factories.Pkcs11LibraryFactory.LoadPkcs11Library(_factories, _libraryPath, AppType.MultiThreaded);
    }

    private static ISlot GetFirstSlotWithToken(IPkcs11Library library)
    {
        List<ISlot> slots = library.GetSlotList(SlotsType.WithTokenPresent);
        if (slots.Count == 0)
            throw new InvalidOperationException("Нема приклучен токен.");
        return slots[0];
    }

    private byte[] ReadCertificateDer(ISession session)
    {
        List<IObjectAttribute> searchAttrs = new List<IObjectAttribute>
        {
            _factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_CERTIFICATE)
        };
        List<IObjectHandle> certObjects = session.FindAllObjects(searchAttrs);
        if (certObjects.Count == 0)
            throw new InvalidOperationException("Не е најден сертификат на токенот.");

        List<IObjectAttribute> values = session.GetAttributeValue(certObjects[0], new List<CKA> { CKA.CKA_VALUE });
        return values[0].GetValueAsByteArray();
    }

    private IObjectHandle FindPrivateKey(ISession session)
    {
        List<IObjectAttribute> searchAttrs = new List<IObjectAttribute>
        {
            _factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_PRIVATE_KEY)
        };
        List<IObjectHandle> keyObjects = session.FindAllObjects(searchAttrs);
        if (keyObjects.Count == 0)
            throw new InvalidOperationException("Не е најден приватен клуч на токенот.");
        return keyObjects[0];
    }
}
```

Save as `efaktura-bridge/src/EfakturaBridge.Core/Pkcs11SigningService.cs`.

- [ ] **Step 5: Build Core**

Run:
```powershell
dotnet build src\EfakturaBridge.Core
```
Expected: `Build succeeded.`

- [ ] **Step 6: Commit**

```bash
git add efaktura-bridge/
git commit -m "feat(efaktura-bridge): extract reusable Pkcs11SigningService from the spike"
```

---

### Task 4: `RequestRouter` — testable HTTP routing, CORS/Origin enforcement, and error handling

**Files:**
- Create: `efaktura-bridge/src/EfakturaBridge.Server/EfakturaBridge.Server.csproj`
- Create: `efaktura-bridge/src/EfakturaBridge.Server/BridgeRequest.cs`
- Create: `efaktura-bridge/src/EfakturaBridge.Server/BridgeResponse.cs`
- Create: `efaktura-bridge/src/EfakturaBridge.Server/SignRequest.cs`
- Create: `efaktura-bridge/src/EfakturaBridge.Server/SignResponse.cs`
- Create: `efaktura-bridge/src/EfakturaBridge.Server/RequestRouter.cs`
- Create: `efaktura-bridge/tests/EfakturaBridge.Server.Tests/EfakturaBridge.Server.Tests.csproj`
- Create: `efaktura-bridge/tests/EfakturaBridge.Server.Tests/FakeSigningService.cs`
- Create: `efaktura-bridge/tests/EfakturaBridge.Server.Tests/RequestRouterTests.cs`

**Interfaces:**
- Consumes: `EfakturaBridge.Core.IPkcs11SigningService`, `CertificateInfo` (Task 3), `Base64Url` (Task 1).
- Produces: `EfakturaBridge.Server.RequestRouter.Handle(BridgeRequest) -> BridgeResponse` — a pure function with no socket I/O, consumed by Task 5's `Program.cs`.

This is the layer that's actually unit-testable without hardware: it's routing, header logic, and JSON shape, driven by a `FakeSigningService` test double instead of the real PKCS#11 implementation.

Design decision for `Origin` handling: a request with **no** `Origin` header (e.g. a local `curl` diagnostic, not a browser) is allowed through with no CORS headers added — the Origin check exists to stop a malicious website open in the same browser from silently calling the bridge, not to block local command-line diagnostics. A request **with** an `Origin` header that doesn't exactly match `https://portal.financebuddy.mk` is rejected with `403`.

- [ ] **Step 1: Create the Server project and test project**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet new console -n EfakturaBridge.Server -o src\EfakturaBridge.Server -f net8.0
dotnet new xunit -n EfakturaBridge.Server.Tests -o tests\EfakturaBridge.Server.Tests -f net8.0
dotnet sln add src\EfakturaBridge.Server\EfakturaBridge.Server.csproj
dotnet sln add tests\EfakturaBridge.Server.Tests\EfakturaBridge.Server.Tests.csproj
dotnet add src\EfakturaBridge.Server\EfakturaBridge.Server.csproj reference src\EfakturaBridge.Core\EfakturaBridge.Core.csproj
dotnet add tests\EfakturaBridge.Server.Tests\EfakturaBridge.Server.Tests.csproj reference src\EfakturaBridge.Server\EfakturaBridge.Server.csproj
dotnet add tests\EfakturaBridge.Server.Tests\EfakturaBridge.Server.Tests.csproj reference src\EfakturaBridge.Core\EfakturaBridge.Core.csproj
```
Expected: no errors.

Delete the generated placeholder `src\EfakturaBridge.Server\Program.cs` content for now (it's rewritten in Task 5) — leave an empty top-level `Console.WriteLine("placeholder");` so the project still builds until Task 5.

- [ ] **Step 2: Write the request/response DTOs**

```csharp
using System.Collections.Generic;

namespace EfakturaBridge.Server;

public sealed class BridgeRequest
{
    public required string Method { get; init; }
    public required string Path { get; init; }
    public string? OriginHeader { get; init; }
    public string? Body { get; init; }
}
```
Save as `efaktura-bridge/src/EfakturaBridge.Server/BridgeRequest.cs`.

```csharp
using System.Collections.Generic;

namespace EfakturaBridge.Server;

public sealed class BridgeResponse
{
    public required int StatusCode { get; init; }
    public required string Body { get; init; }
    public string ContentType { get; init; } = "application/json";
    public Dictionary<string, string> Headers { get; init; } = new();
}
```
Save as `efaktura-bridge/src/EfakturaBridge.Server/BridgeResponse.cs`.

```csharp
namespace EfakturaBridge.Server;

public sealed record SignRequest(string? Data);
```
Save as `efaktura-bridge/src/EfakturaBridge.Server/SignRequest.cs`.

```csharp
namespace EfakturaBridge.Server;

public sealed record SignResponse(string Signature);
```
Save as `efaktura-bridge/src/EfakturaBridge.Server/SignResponse.cs`.

- [ ] **Step 3: Write the failing tests for `RequestRouter`**

```csharp
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
```

Save as `efaktura-bridge/tests/EfakturaBridge.Server.Tests/RequestRouterTests.cs`.

- [ ] **Step 4: Write the `FakeSigningService` test double**

```csharp
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
```

Save as `efaktura-bridge/tests/EfakturaBridge.Server.Tests/FakeSigningService.cs`.

- [ ] **Step 5: Run the tests and confirm they fail to compile**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet test tests\EfakturaBridge.Server.Tests
```
Expected: build FAILS with `error CS0246: The type or namespace name 'RequestRouter' could not be found`.

- [ ] **Step 6: Implement `RequestRouter`**

```csharp
using System;
using System.Collections.Generic;
using System.Text.Json;
using EfakturaBridge.Core;

namespace EfakturaBridge.Server;

public sealed class RequestRouter
{
    private const string AllowedOrigin = "https://portal.financebuddy.mk";

    private readonly IPkcs11SigningService _signingService;

    public RequestRouter(IPkcs11SigningService signingService)
    {
        _signingService = signingService;
    }

    public BridgeResponse Handle(BridgeRequest request)
    {
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
            return new BridgeResponse { StatusCode = 200, Body = JsonSerializer.Serialize(info) };
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
            signRequest = JsonSerializer.Deserialize<SignRequest>(body);
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
            string json = JsonSerializer.Serialize(new SignResponse(Base64Url.Encode(signature)));
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
```

Save as `efaktura-bridge/src/EfakturaBridge.Server/RequestRouter.cs`.

- [ ] **Step 7: Run the tests and confirm they pass**

Run:
```powershell
dotnet test tests\EfakturaBridge.Server.Tests
```
Expected: `Passed! - Failed: 0, Passed: 10`.

- [ ] **Step 8: Commit**

```bash
git add efaktura-bridge/
git commit -m "feat(efaktura-bridge): add RequestRouter with CORS/Origin enforcement and unit tests"
```

---

### Task 5: Wire `RequestRouter` into a real `HttpListener`, verify live against the real token

**Files:**
- Modify: `efaktura-bridge/src/EfakturaBridge.Server/Program.cs`

**Interfaces:**
- Consumes: `EfakturaBridge.Server.RequestRouter` (Task 4), `EfakturaBridge.Core.Pkcs11SigningService` (Task 3).
- Produces: a running local HTTP service at `http://127.0.0.1:9847/` — the deliverable this whole plan builds toward.

- [ ] **Step 1: Write the `HttpListener` wiring**

```csharp
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
listener.Start();
Console.WriteLine("Локалниот мост слуша на http://127.0.0.1:9847 (Ctrl+C за прекин)");

while (true)
{
    HttpListenerContext context = listener.GetContext();
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
```

Save as `efaktura-bridge/src/EfakturaBridge.Server/Program.cs` (replacing the Task 4 placeholder).

- [ ] **Step 2: Build**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet build src\EfakturaBridge.Server
```
Expected: `Build succeeded.`

- [ ] **Step 3: Start the server and verify `/health` + Origin enforcement (no token needed for this step)**

In one terminal:
```powershell
dotnet run --project src\EfakturaBridge.Server
```
Expected: prints `Локалниот мост слуша на http://127.0.0.1:9847 ...` and keeps running.

In a second terminal:
```powershell
curl.exe http://127.0.0.1:9847/health
curl.exe -H "Origin: https://portal.financebuddy.mk" -i http://127.0.0.1:9847/health
curl.exe -H "Origin: https://evil.example" -i http://127.0.0.1:9847/health
```
Expected:
- First call: `{"status":"ok"}`, HTTP 200.
- Second call: HTTP 200, response headers include `Access-Control-Allow-Origin: https://portal.financebuddy.mk`.
- Third call: HTTP 403.

Stop the server (Ctrl+C in the first terminal) once confirmed.

- [ ] **Step 4: Verify `/certificate` and `/sign` against the real token — THIS STEP IS FOR THE USER**

With the USB token physically plugged in, start the server again:
```powershell
dotnet run --project src\EfakturaBridge.Server
```

In a second terminal:
```powershell
curl.exe http://127.0.0.1:9847/certificate
```
Expected: HTTP 200 with JSON containing `serialNumber`, `subjectName`, `notBefore`, `notAfter`, `certificateBase64`. (The SafeNet PIN dialog should NOT appear for this call — reading the certificate doesn't require login.)

Then, using PowerShell to base64url-encode a test string for the `/sign` call:
```powershell
$bytes = [System.Text.Encoding]::UTF8.GetBytes("test-signing-input")
$b64url = [Convert]::ToBase64String($bytes).Replace('+','-').Replace('/','_').TrimEnd('=')
curl.exe -X POST -H "Content-Type: application/json" -d "{\"data\":\"$b64url\"}" http://127.0.0.1:9847/sign
```
Expected: the SafeNet PIN dialog appears (or a console PIN prompt, if `ProtectedAuthenticationPath` was `false` per Task 2's finding) — enter the PIN — then HTTP 200 with JSON `{"signature":"<base64url string>"}`.

Paste back both responses (certificate JSON and sign JSON, PIN itself never included) to confirm before moving to Task 6.

- [ ] **Step 5: Commit**

```bash
git add efaktura-bridge/
git commit -m "feat(efaktura-bridge): wire RequestRouter into HttpListener, verify live against the token"
```

---

### Task 6: Publish as a single portable self-contained `.exe`

**Files:**
- No new source files — this task produces a build artifact.

**Interfaces:**
- Consumes: `EfakturaBridge.Server` project (Task 5).
- Produces: `efaktura-bridge/publish/EfakturaBridge.Server.exe` — the artifact plan 8b-ii will later upload to the tami-web-app Settings download page.

- [ ] **Step 1: Publish**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet publish src\EfakturaBridge.Server\EfakturaBridge.Server.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o publish
```
Expected: `EfakturaBridge.Server.exe` appears in `efaktura-bridge\publish\`.

- [ ] **Step 2: Verify the published exe runs standalone (no `dotnet` command involved)**

In one terminal:
```powershell
.\publish\EfakturaBridge.Server.exe
```
Expected: same startup message as Task 5, `Локалниот мост слуша на http://127.0.0.1:9847 ...`.

In a second terminal:
```powershell
curl.exe http://127.0.0.1:9847/health
```
Expected: `{"status":"ok"}`, HTTP 200. Stop the server (Ctrl+C) once confirmed.

- [ ] **Step 3: Commit**

The `publish/` folder is gitignored (Task 1's `.gitignore`), so there's nothing new to add — this step just confirms the working tree is clean.

```bash
git status
```
Expected: `nothing to commit, working tree clean`.

---

## Self-Review Notes

- **Spec coverage:** design doc §А (three endpoints, fixed port, Origin-only access, "dumb" bridge, PIN never touches app code when protected-auth-path is available, .NET/Pkcs11Interop/HttpListener choice, single portable exe) — all covered across Tasks 1–6. The design's own 2-step spike plan ("first: minimal console app proving connectivity" then "add the web service") maps directly to Task 2 then Tasks 3–5.
- **Explicitly out of scope for this plan** (deferred to 8b-ii per the design doc and the user's chosen two-plan split): JWS assembly, Laravel routes, the browser-side JS calling sequence, the Phase 8a UI rework ("Регистрирај потпишувачки уред"), uploading the `.exe` to Settings, and the migration dropping the old cert-storage columns.
- **Type consistency check:** `IPkcs11SigningService.GetCertificateInfo()`/`Sign(byte[])` (Task 3) match exactly what `RequestRouter` (Task 4) and `FakeSigningService` (Task 4) call; `Pkcs11SigningService` (Task 3) implements the same interface with the same signatures. `Base64Url.Encode`/`Decode` (Task 1) are used identically in the spike (Task 2), `RequestRouter` (Task 4), and the live-verification curl commands (Task 5).
