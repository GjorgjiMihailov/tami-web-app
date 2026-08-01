# е-Фактура 8b-ii: Laravel JWS-составување, испраќање до УЈП, и UI за потпишувачки уред Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the е-Фактура feature end-to-end: harden the 8b-i local signing bridge against its three deferred risks (PIN model, Origin/Host spoofing, Chrome's Private Network Access), replace the Phase 8a "upload a certificate file" UI with "Регистрирај потпишувачки уред" (register a signing device), and wire a real sign-and-send flow — Laravel builds the JWS signing-input for a confirmed sales invoice, the browser relays it to the local bridge for signing, and Laravel assembles the final compact JWS and posts it to `efakturatest.ujp.gov.mk`.

**Architecture:** Two Laravel routes (`POST .../efaktura/signing-input`, `POST .../efaktura/send`) sandwich a browser-side JS sequence that talks to the already-built `.NET` bridge on `127.0.0.1:9847`. The JWS itself is hand-assembled (base64url header + "." + base64url payload, later + "." + base64url signature) rather than built with `web-token/jwt-framework` — that library's `JWSBuilder` assumes it holds an in-process signing key and calls it synchronously; here the "key" is a hardware token on a different step of a browser-mediated round trip, which doesn't fit that synchronous callback model at all. The library is removed. A short-lived server-side cache (`Cache::put`/`Cache::pull`, default `database` store, TTL 10 minutes) holds the exact signing-input string between the two requests so the signature is never checked against a re-derived (and potentially drifted) payload.

**Tech Stack:** Laravel (PHP), Livewire 3 + Alpine.js (already bundled, no new frontend dependency), `Illuminate\Support\Facades\Http` for the UJP call (same client already used by `ExchangeRateService`), .NET 8 / `Pkcs11Interop` for the bridge-side fixes.

## Global Constraints

- **Hand-rolled JWS, not `web-token/jwt-framework`.** Decided above — remove the dependency (Task 16). Compact JWS = `base64url(header_json) . "." . base64url(payload_json) . "." . base64url(raw_signature_bytes)`, all base64url per RFC 4648 §5 (no padding). `x5c` per RFC 7515 §4.1.6 is **standard** base64 of the DER certificate bytes (not base64url) — the bridge's `/certificate` endpoint already returns `certificateBase64` in exactly that standard-base64 DER form, so no reformatting is needed before putting it in `x5c`.
- **Only `efaktura_credential_mode = own` is wired to actually sign+send in this plan.** `firm` mode's credential-resolution semantics — whose `X-EUJP-ID`/token applies when the firm signs on behalf of a client company — is a distinct, undocumented question that Phase 8a never actually resolved (there is no "this Company record IS the firm" flag anywhere in the schema) and that production's one real company (id 2, currently `firm` mode with zero access configured) doesn't force a decision on yet. Guessing at this would risk the exact "silently wrong" bug class already called out for the JWS format. The send endpoint returns a clear 422 ("Праќање на е-Фактура преку фирмениот сертификат сè уште не е поддржано...") for `firm`-mode companies instead of guessing. Revisit when a second real company actually needs firm-fallback sending.
- **Tax-indicator mapping is fixed by `danocni_indikatori_27072026.pdf` (v3, 27/07/2026), read directly for this plan:** `standard`+18% → `DDV-A`, `standard`+10% → `DDV-V`, `standard`+5% → `DDV-B`, `export` → `DDV-7-I`, `exempt_with_credit` → `DDV-8`, `exempt_without_credit` → `DDV-9`. All six carry `vatPercent` equal to their nominal rate except the last three, which are 0. Exact code strings, not paraphrased.
- **Payment-type labels (P10–P19)** per project memory (already confirmed from the sifrarnik in an earlier session): `P10` Готово, `P11` Картичка, `P12` Плаќање преку банка (**default**), `P13` Рати, `P14` Онлајн-банка, `P15` Мобилна апликација, `P16` Без надомест, `P17` Компензација, `P18` Ваучер, `P19` Друго.
- **Bridge fixed port stays `127.0.0.1:9847`**, allowed browser Origin stays exactly `https://portal.financebuddy.mk` — both already established in 8b-i, unchanged here.
- **NuGetScratch workaround still applies on this machine**: set `$env:TMP`/`$env:TEMP` to `C:\Users\FinanceBuddy.mk\dotnet-temp` before every `dotnet` command (Tasks 1–4).
- **Hardware-dependent bridge steps cannot be exercised by CI** — they require the physical SafeNet USB token and a real interactive desktop session. Those specific steps are marked **THIS STEP IS FOR THE USER**, same convention as 8b-i.
- **PDF references already on disk** (project root, not git-committed): `danocni_indikatori_27072026.pdf` (tax-indicator table, read in full for this plan), `primer_za_json_2.pdf` (worked JSON examples, basis for the document shape below).

---

## File Structure

```
tami-web-app/
  efaktura-bridge/
    src/EfakturaBridge.Core/Pkcs11SigningService.cs                    ← Modify (Task 1)
    src/EfakturaBridge.Server/BridgeRequest.cs                          ← Modify (Task 3)
    src/EfakturaBridge.Server/RequestRouter.cs                          ← Modify (Task 2, Task 3)
    src/EfakturaBridge.Server/Program.cs                                ← Modify (Task 3)
    tests/EfakturaBridge.Server.Tests/RequestRouterTests.cs             ← Modify (Task 2, Task 3)
    publish/EfakturaBridge.Server.exe                                   ← rebuilt, gitignored (Task 4)
  public/downloads/efaktura-bridge/EfakturaBridge.Server.exe            ← Create, committed binary (Task 4)
  database/migrations/
    2026_08_01_090000_add_structured_address_to_companies_and_partners_table.php   ← Create (Task 5)
    2026_08_01_090100_replace_efaktura_certificate_storage_with_signing_device_metadata.php ← Create (Task 6)
    2026_08_01_090200_add_efaktura_send_tracking_to_sales_invoices_table.php        ← Create (Task 7)
  app/Models/Company.php                                                ← Modify (Task 5, Task 6)
  app/Models/Partner.php                                                ← Modify (Task 5)
  app/Models/SalesInvoice.php                                           ← Modify (Task 7)
  app/Livewire/CompanyDashboard.php                                     ← Modify (Task 5, Task 6, Task 8)
  resources/views/livewire/company-dashboard.blade.php                  ← Modify (Task 5, Task 8)
  app/Livewire/PartnerShow.php                                          ← Modify (Task 9)
  resources/views/livewire/partner-show.blade.php                       ← Modify (Task 9)
  app/Livewire/Invoicing/SalesInvoiceForm.php                           ← Modify (Task 10)
  resources/views/livewire/invoicing/sales-invoice-form.blade.php       ← Modify (Task 10)
  app/Support/Base64Url.php                                             ← Create (Task 11)
  tests/Unit/Support/Base64UrlTest.php                                  ← Create (Task 11)
  app/Services/Efaktura/EfakturaTaxIndicator.php                        ← Create (Task 12)
  tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php             ← Create (Task 12)
  app/Services/Efaktura/EfakturaDocumentBuilder.php                     ← Create (Task 13)
  tests/Unit/Services/Efaktura/EfakturaDocumentBuilderTest.php          ← Create (Task 13)
  app/Services/Efaktura/EfakturaJwsService.php                          ← Create (Task 14)
  tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php                ← Create (Task 14)
  app/Http/Controllers/EfakturaSendController.php                       ← Create (Task 15)
  routes/web.php                                                        ← Modify (Task 15)
  tests/Feature/EfakturaSendControllerTest.php                          ← Create (Task 15)
  composer.json / composer.lock                                        ← Modify (Task 16)
  app/Livewire/Invoicing/SalesInvoiceShow.php                           ← Modify (Task 17)
  resources/views/livewire/invoicing/sales-invoice-show.blade.php       ← Modify (Task 17)
  tests/Feature/SalesInvoiceShowEfakturaTest.php                        ← Create (Task 17)
```

---

### Task 1: Bridge spike — simplify PIN handling, drop the dead console-prompt fallback

**Files:**
- Modify: `efaktura-bridge/src/EfakturaBridge.Core/Pkcs11SigningService.cs`

**Interfaces:**
- Consumes: nothing new.
- Produces: same `IPkcs11SigningService.Sign(byte[]) -> byte[]` signature (Task 3/4 of 8b-i) — behavior changes, contract doesn't.

Live testing in 8b-i found that on the real token, SafeNet's own popup appears regardless of the `ProtectedAuthenticationPath` flag, and the console `Console.ReadLine()` fallback doesn't even accept input while that popup is up — it just blocks the single-threaded `HttpListener` accept loop, stalling `/health` for every other caller. The fix: always call `Login` with a `null` PIN and delete the console-prompt branch entirely.

- [ ] **Step 1: Simplify `Sign` to always use a null-PIN login**

In `efaktura-bridge/src/EfakturaBridge.Core/Pkcs11SigningService.cs`, replace:

```csharp
        if (tokenInfo.TokenFlags.ProtectedAuthenticationPath)
        {
            session.Login(CKU.CKU_USER, (string?)null);
        }
        else
        {
            Console.Write("Внесете PIN за токенот: ");
            string? pin = Console.ReadLine();
            session.Login(CKU.CKU_USER, pin);
        }
```

with:

```csharp
        // SafeNet's own popup handles PIN entry regardless of what ProtectedAuthenticationPath
        // reports (confirmed against the real token in plan 8b-i) — a console-input fallback
        // here would silently block the single-threaded HTTP accept loop forever, since the
        // console prompt receives no input while SafeNet's popup has focus.
        session.Login(CKU.CKU_USER, (string?)null);
```

- [ ] **Step 2: Build**

Run:
```powershell
$env:TMP = "C:\Users\FinanceBuddy.mk\dotnet-temp"
$env:TEMP = "C:\Users\FinanceBuddy.mk\dotnet-temp"
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet build src\EfakturaBridge.Core
```
Expected: `Build succeeded.`

- [ ] **Step 3: Live-verify against the real token — THIS STEP IS FOR THE USER**

With the USB token plugged in:
```powershell
dotnet run --project src\EfakturaBridge.Server
```
In a second terminal:
```powershell
$bytes = [System.Text.Encoding]::UTF8.GetBytes("pin-model-spike-test")
$b64url = [Convert]::ToBase64String($bytes).Replace('+','-').Replace('/','_').TrimEnd('=')
curl.exe -X POST -H "Content-Type: application/json" -d "{\"data\":\"$b64url\"}" http://127.0.0.1:9847/sign
```
Expected: **only** the SafeNet popup appears (no console text prompt at all), entering the PIN there completes the call, and the terminal prints `{"signature":"..."}` promptly afterward — no hang, no stuck console. Paste back the console output and confirm before continuing.

- [ ] **Step 4: Commit**

```bash
git add efaktura-bridge/
git commit -m "fix(efaktura-bridge): always use SafeNet's own PIN popup, drop dead console fallback"
```

---

### Task 2: Bridge hardening — reject requests unless `Host` is the bridge itself (DNS-rebinding mitigation)

**Files:**
- Modify: `efaktura-bridge/src/EfakturaBridge.Server/BridgeRequest.cs`
- Modify: `efaktura-bridge/src/EfakturaBridge.Server/RequestRouter.cs`
- Modify: `efaktura-bridge/src/EfakturaBridge.Server/Program.cs`
- Modify: `efaktura-bridge/tests/EfakturaBridge.Server.Tests/RequestRouterTests.cs`

**Interfaces:**
- Consumes: `EfakturaBridge.Core.IPkcs11SigningService` (unchanged).
- Produces: `RequestRouter.Handle(BridgeRequest)` now also rejects (403) any request whose `Host` header isn't `127.0.0.1:9847` or `localhost:9847` — closes the gap where a DNS-rebinding attack makes the browser send no `Origin` header at all (same-origin requests omit it), which previously sailed through the Origin-only check.

- [ ] **Step 1: Add `HostHeader` to `BridgeRequest`**

```csharp
using System.Collections.Generic;

namespace EfakturaBridge.Server;

public sealed class BridgeRequest
{
    public required string Method { get; init; }
    public required string Path { get; init; }
    public string? OriginHeader { get; init; }
    public string? HostHeader { get; init; }
    public string? Body { get; init; }
}
```

Save as `efaktura-bridge/src/EfakturaBridge.Server/BridgeRequest.cs`.

- [ ] **Step 2: Write the failing tests**

Add to `efaktura-bridge/tests/EfakturaBridge.Server.Tests/RequestRouterTests.cs` (inside the `RequestRouterTests` class):

```csharp
    [Fact]
    public void Health_UnexpectedHost_Returns403()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "GET",
            Path = "/health",
            HostHeader = "attacker.example",
        });

        Assert.Equal(403, response.StatusCode);
    }

    [Fact]
    public void Health_LoopbackHost_Returns200()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "GET",
            Path = "/health",
            HostHeader = "127.0.0.1:9847",
        });

        Assert.Equal(200, response.StatusCode);
    }

    [Fact]
    public void Health_LocalhostHost_Returns200()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "GET",
            Path = "/health",
            HostHeader = "localhost:9847",
        });

        Assert.Equal(200, response.StatusCode);
    }

    [Fact]
    public void Health_MissingHost_Returns403()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest { Method = "GET", Path = "/health" });

        Assert.Equal(403, response.StatusCode);
    }
```

Note this changes the meaning of the existing `Health_NoOriginHeader_Returns200` test (it never set `HostHeader` either, so it will now fail with 403 instead of 200) — update it to also set `HostHeader = "127.0.0.1:9847"`:

```csharp
    [Fact]
    public void Health_NoOriginHeader_Returns200()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "GET",
            Path = "/health",
            HostHeader = "127.0.0.1:9847",
        });

        Assert.Equal(200, response.StatusCode);
        Assert.Contains("\"status\":\"ok\"", response.Body);
    }
```

Every other existing test in the file that expects a non-403 response (`Health_AllowedOrigin_Returns200WithCorsHeader`, `Options_AllowedOrigin_Returns204WithCorsHeaders`, `Certificate_ReturnsSigningServiceInfoAsJson`, `Certificate_WhenSigningServiceThrows_Returns500`, `Sign_ValidBase64UrlData_ReturnsSignature`, `Sign_MissingBody_Returns400`, `Sign_InvalidJson_Returns400`, `Sign_InvalidBase64Url_Returns400`, `UnknownRoute_Returns404`) must likewise add `HostHeader = "127.0.0.1:9847"` to their `BridgeRequest` initializer, or they'll now fail with 403 before reaching the logic they're testing. `Health_DisallowedOrigin_Returns403` doesn't need the change (it already expects 403).

- [ ] **Step 3: Run tests and confirm the new/updated ones fail**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet test tests\EfakturaBridge.Server.Tests
```
Expected: FAIL — `Health_UnexpectedHost_Returns403` etc. don't exist as behavior yet (router doesn't check `HostHeader`), and the updated tests without `HostHeader` set now get 403 where they expect 200/204/500/400/404.

- [ ] **Step 4: Implement the Host check in `RequestRouter`**

In `efaktura-bridge/src/EfakturaBridge.Server/RequestRouter.cs`, add a constant and a check at the top of `Handle`:

```csharp
    private const string AllowedOrigin = "https://portal.financebuddy.mk";
    private static readonly string[] AllowedHosts = { "127.0.0.1:9847", "localhost:9847" };

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
```

(Everything below that in the file — `BuildCorsHeaders`, `HandleHealth`, `HandleCertificate`, `HandleSign` — is unchanged.)

- [ ] **Step 5: Wire `req.Headers["Host"]` into `BridgeRequest` in `Program.cs`**

In `efaktura-bridge/src/EfakturaBridge.Server/Program.cs`, change:

```csharp
        BridgeRequest bridgeRequest = new BridgeRequest
        {
            Method = req.HttpMethod,
            Path = req.Url?.AbsolutePath ?? "/",
            OriginHeader = req.Headers["Origin"],
            Body = body,
        };
```

to:

```csharp
        BridgeRequest bridgeRequest = new BridgeRequest
        {
            Method = req.HttpMethod,
            Path = req.Url?.AbsolutePath ?? "/",
            OriginHeader = req.Headers["Origin"],
            HostHeader = req.Headers["Host"],
            Body = body,
        };
```

- [ ] **Step 6: Run tests and confirm they pass**

Run:
```powershell
dotnet test tests\EfakturaBridge.Server.Tests
```
Expected: `Passed! - Failed: 0`.

- [ ] **Step 7: Build and manually confirm against a spoofed Host**

Run:
```powershell
dotnet build src\EfakturaBridge.Server
dotnet run --project src\EfakturaBridge.Server
```
In a second terminal:
```powershell
curl.exe -i http://127.0.0.1:9847/health
curl.exe -i -H "Host: attacker.example" http://127.0.0.1:9847/health
```
Expected: first call HTTP 200, second call HTTP 403 (`curl` lets you override `Host` independently of the connection target, which is exactly what a DNS-rebinding page would do). Stop the server (Ctrl+C).

- [ ] **Step 8: Commit**

```bash
git add efaktura-bridge/
git commit -m "fix(efaktura-bridge): reject requests with a spoofed Host header (DNS-rebinding hardening)"
```

---

### Task 3: Bridge — Private Network Access (PNA) preflight support, then a real browser verification

**Files:**
- Modify: `efaktura-bridge/src/EfakturaBridge.Server/BridgeRequest.cs`
- Modify: `efaktura-bridge/src/EfakturaBridge.Server/RequestRouter.cs`
- Modify: `efaktura-bridge/src/EfakturaBridge.Server/Program.cs`
- Modify: `efaktura-bridge/tests/EfakturaBridge.Server.Tests/RequestRouterTests.cs`

**Interfaces:**
- Consumes: `BridgeRequest.HostHeader` (Task 2).
- Produces: `RequestRouter.Handle` now answers a PNA preflight (`OPTIONS` with `Access-Control-Request-Private-Network: true`) with `Access-Control-Allow-Private-Network: true` alongside the existing CORS headers — this is the header Chrome requires before it will let a public HTTPS page (`portal.financebuddy.mk`) call a private-network target (`127.0.0.1`) at all.

Chrome's Private Network Access spec sends a **preflight `OPTIONS`** for any request from a public site to a private IP, carrying `Access-Control-Request-Private-Network: true`. The bridge's current `RequestRouter` already returns 204 + CORS headers for any `OPTIONS`, but never inspects or answers that specific header — so Chrome's preflight succeeds on CORS grounds but the browser still blocks the real request because the PNA-specific permission was never granted. This task fixes that, but **the only way to actually prove it works is a live `fetch()` from the real page** — 8b-i only ever verified with `curl`, which never triggers preflight/PNA logic at all.

- [ ] **Step 1: Add `PrivateNetworkRequested` to `BridgeRequest`**

```csharp
using System.Collections.Generic;

namespace EfakturaBridge.Server;

public sealed class BridgeRequest
{
    public required string Method { get; init; }
    public required string Path { get; init; }
    public string? OriginHeader { get; init; }
    public string? HostHeader { get; init; }
    public bool PrivateNetworkRequested { get; init; }
    public string? Body { get; init; }
}
```

Save as `efaktura-bridge/src/EfakturaBridge.Server/BridgeRequest.cs`.

- [ ] **Step 2: Write the failing test**

Add to `RequestRouterTests.cs`:

```csharp
    [Fact]
    public void Options_PrivateNetworkRequested_IncludesPnaHeader()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "OPTIONS",
            Path = "/sign",
            OriginHeader = AllowedOrigin,
            HostHeader = "127.0.0.1:9847",
            PrivateNetworkRequested = true,
        });

        Assert.Equal(204, response.StatusCode);
        Assert.Equal("true", response.Headers["Access-Control-Allow-Private-Network"]);
    }

    [Fact]
    public void Options_NoPrivateNetworkRequested_OmitsPnaHeader()
    {
        RequestRouter router = CreateRouter();

        BridgeResponse response = router.Handle(new BridgeRequest
        {
            Method = "OPTIONS",
            Path = "/sign",
            OriginHeader = AllowedOrigin,
            HostHeader = "127.0.0.1:9847",
        });

        Assert.Equal(204, response.StatusCode);
        Assert.False(response.Headers.ContainsKey("Access-Control-Allow-Private-Network"));
    }
```

- [ ] **Step 3: Run tests and confirm they fail**

Run:
```powershell
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet test tests\EfakturaBridge.Server.Tests
```
Expected: FAIL — `Access-Control-Allow-Private-Network` key not present in `response.Headers` for the first test.

- [ ] **Step 4: Implement in `RequestRouter`**

In `efaktura-bridge/src/EfakturaBridge.Server/RequestRouter.cs`, change the `OPTIONS` branch of `Handle`:

```csharp
        if (request.Method == "OPTIONS")
        {
            if (request.PrivateNetworkRequested)
                corsHeaders["Access-Control-Allow-Private-Network"] = "true";

            return new BridgeResponse { StatusCode = 204, Body = "", Headers = corsHeaders };
        }
```

(This replaces the previous single-line `if (request.Method == "OPTIONS") return new BridgeResponse { StatusCode = 204, Body = "", Headers = corsHeaders };`.)

- [ ] **Step 5: Wire the header into `BridgeRequest` in `Program.cs`**

In `efaktura-bridge/src/EfakturaBridge.Server/Program.cs`:

```csharp
        BridgeRequest bridgeRequest = new BridgeRequest
        {
            Method = req.HttpMethod,
            Path = req.Url?.AbsolutePath ?? "/",
            OriginHeader = req.Headers["Origin"],
            HostHeader = req.Headers["Host"],
            PrivateNetworkRequested = req.Headers["Access-Control-Request-Private-Network"] == "true",
            Body = body,
        };
```

- [ ] **Step 6: Run tests and confirm they pass**

Run:
```powershell
dotnet test tests\EfakturaBridge.Server.Tests
```
Expected: `Passed! - Failed: 0`.

- [ ] **Step 7: Build**

Run:
```powershell
dotnet build src\EfakturaBridge.Server
```
Expected: `Build succeeded.`

- [ ] **Step 8: Real browser verification from the actual page — THIS STEP IS FOR THE USER**

Start the bridge:
```powershell
dotnet run --project src\EfakturaBridge.Server
```

Open `https://portal.financebuddy.mk` in Chrome, log in, open DevTools → Console, and run:
```javascript
fetch('http://127.0.0.1:9847/health').then(r => r.json()).then(console.log).catch(e => console.error('FAILED:', e))
```

Expected: prints `{status: "ok"}`. If Chrome instead blocks it (console shows a Private Network Access / mixed-content / CORS error), paste back the **exact** console error — that's the signal for whether Task 3's header is sufficient or whether Chrome additionally requires the page itself to declare a `<meta http-equiv="Content-Security-Policy" ...>` /  Permissions-Policy opt-in, or whether the page needs to be served with a `treat-as-public-address` style header, which would need a follow-up fix here before any invoice-signing UI is built on top of it. **Do not proceed to Task 8 onward until this call succeeds from the real page.**

- [ ] **Step 9: Commit**

```bash
git add efaktura-bridge/
git commit -m "feat(efaktura-bridge): answer Private Network Access preflight, verify live from portal.financebuddy.mk"
```

---

### Task 4: Publish the updated bridge and commit it as a downloadable artifact

**Files:**
- Create: `public/downloads/efaktura-bridge/EfakturaBridge.Server.exe` (committed binary)

**Interfaces:**
- Consumes: `EfakturaBridge.Server` project (Tasks 1–3).
- Produces: a static file served directly by Apache at `https://portal.financebuddy.mk/downloads/efaktura-bridge/EfakturaBridge.Server.exe` — no Laravel route needed, it's a plain public file under `public/`. Task 8's "Преземи локален потпишувач" link points here.

- [ ] **Step 1: Publish**

Run:
```powershell
$env:TMP = "C:\Users\FinanceBuddy.mk\dotnet-temp"
$env:TEMP = "C:\Users\FinanceBuddy.mk\dotnet-temp"
cd "C:\Users\FinanceBuddy.mk\Documents\tami-web-app\efaktura-bridge"
dotnet publish src\EfakturaBridge.Server\EfakturaBridge.Server.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o publish
```
Expected: `EfakturaBridge.Server.exe` appears in `efaktura-bridge\publish\`.

- [ ] **Step 2: Copy into the public downloads path**

Run (from repo root):
```powershell
New-Item -ItemType Directory -Force -Path "public\downloads\efaktura-bridge" | Out-Null
Copy-Item "efaktura-bridge\publish\EfakturaBridge.Server.exe" "public\downloads\efaktura-bridge\EfakturaBridge.Server.exe" -Force
```
Expected: `public\downloads\efaktura-bridge\EfakturaBridge.Server.exe` exists.

- [ ] **Step 3: Verify the published exe still runs standalone**

In one terminal:
```powershell
.\efaktura-bridge\publish\EfakturaBridge.Server.exe
```
In a second terminal:
```powershell
curl.exe -i http://127.0.0.1:9847/health
curl.exe -i -H "Host: attacker.example" http://127.0.0.1:9847/health
```
Expected: first call 200, second call 403 — confirms Tasks 1–3's fixes are present in the actual published binary, not just in `dotnet run`. Stop the server (Ctrl+C).

- [ ] **Step 4: Commit**

```bash
git add public/downloads/efaktura-bridge/EfakturaBridge.Server.exe
git commit -m "chore(efaktura-bridge): publish hardened bridge exe as a downloadable file"
```

Expected: git accepts the binary (it's outside the `efaktura-bridge/.gitignore`'d `publish/` directory, since it now lives under `public/downloads/`).

---

### Task 5: Migration + models — structured address fields on companies and partners

**Files:**
- Create: `database/migrations/2026_08_01_090000_add_structured_address_to_companies_and_partners_table.php`
- Modify: `app/Models/Company.php`
- Modify: `app/Models/Partner.php`
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `app/Livewire/PartnerShow.php` (validation array only here; the view/UI part is Task 9)
- Test: `tests/Feature/CompanyDashboardStructuredAddressTest.php`

**Interfaces:**
- Produces: `companies.street_address/street_number/postal_code/city` and `partners.street_address/street_number/postal_code/city` (all nullable strings) — consumed by `EfakturaDocumentBuilder` (Task 13) for the UJP `sellerAddress`/`buyerAddress` objects.

UJP requires a structured seller/buyer address (`streetAddress`/`streetNumber`/`postalCode`/`city`) but both `Company` and `Partner` currently only have one free-text `address` column. No automated parsing of the existing free-text field — Macedonian addresses have no single consistent format, so the four new columns start empty and are filled in manually via the existing "Уреди" forms.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('street_address')->nullable()->after('address');
            $table->string('street_number')->nullable()->after('street_address');
            $table->string('postal_code')->nullable()->after('street_number');
            $table->string('city')->nullable()->after('postal_code');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->string('street_address')->nullable()->after('address');
            $table->string('street_number')->nullable()->after('street_address');
            $table->string('postal_code')->nullable()->after('street_number');
            $table->string('city')->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['street_address', 'street_number', 'postal_code', 'city']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['street_address', 'street_number', 'postal_code', 'city']);
        });
    }
};
```

Save as `database/migrations/2026_08_01_090000_add_structured_address_to_companies_and_partners_table.php`.

- [ ] **Step 2: Run the migration**

Run:
```powershell
php artisan migrate
```
Expected: the new migration runs successfully.

- [ ] **Step 3: Add the columns to `Company::$fillable` and `Partner::$fillable`**

In `app/Models/Company.php`, change:
```php
    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
        'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_certificate_path',
        'efaktura_certificate_password', 'efaktura_firm_access_status',
        'efaktura_firm_access_decided_by', 'efaktura_firm_access_decided_at',
    ];
```
to:
```php
    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
        'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
        'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_certificate_path',
        'efaktura_certificate_password', 'efaktura_firm_access_status',
        'efaktura_firm_access_decided_by', 'efaktura_firm_access_decided_at',
    ];
```
(Task 6 removes the `efaktura_certificate_path`/`efaktura_certificate_password` entries and adds the signing-device ones — don't remove them here yet, just add the four address fields.)

In `app/Models/Partner.php`, change:
```php
    protected $fillable = [
        'company_id', 'name', 'type', 'tax_id', 'registration_number',
        'director_name', 'is_vat_registered', 'vat_number',
        'email', 'phone', 'address',
    ];
```
to:
```php
    protected $fillable = [
        'company_id', 'name', 'type', 'tax_id', 'registration_number',
        'director_name', 'is_vat_registered', 'vat_number',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
    ];
```

- [ ] **Step 4: Add the fields to `CompanyDashboard`**

In `app/Livewire/CompanyDashboard.php`, add four public properties near `public string $editAddress = '';`:
```php
    public string $editAddress = '';

    public string $editStreetAddress = '';

    public string $editStreetNumber = '';

    public string $editPostalCode = '';

    public string $editCity = '';
```

In `startEdit()`, add after `$this->editAddress = (string) $this->company->address;`:
```php
        $this->editStreetAddress = (string) $this->company->street_address;
        $this->editStreetNumber = (string) $this->company->street_number;
        $this->editPostalCode = (string) $this->company->postal_code;
        $this->editCity = (string) $this->company->city;
```

In `save()`'s `$this->validate([...])` array, add after `'editAddress' => 'nullable|string|max:255',`:
```php
            'editStreetAddress' => 'nullable|string|max:255',
            'editStreetNumber' => 'nullable|string|max:50',
            'editPostalCode' => 'nullable|string|max:20',
            'editCity' => 'nullable|string|max:255',
```

In `save()`'s `$companyData` array (inside the `DB::transaction` closure), add after `'address' => $validated['editAddress'] ?: null,`:
```php
                'street_address' => $validated['editStreetAddress'] ?: null,
                'street_number' => $validated['editStreetNumber'] ?: null,
                'postal_code' => $validated['editPostalCode'] ?: null,
                'city' => $validated['editCity'] ?: null,
```

- [ ] **Step 5: Add the same four fields to `PartnerShow`**

In `app/Livewire/PartnerShow.php`, add after `public string $editAddress = '';`:
```php
    public string $editStreetAddress = '';

    public string $editStreetNumber = '';

    public string $editPostalCode = '';

    public string $editCity = '';
```

In `startEdit()`, add after `$this->editAddress = (string) $this->partner->address;`:
```php
        $this->editStreetAddress = (string) $this->partner->street_address;
        $this->editStreetNumber = (string) $this->partner->street_number;
        $this->editPostalCode = (string) $this->partner->postal_code;
        $this->editCity = (string) $this->partner->city;
```

In `save()`'s validation array, add after `'editAddress' => 'nullable|string|max:255',`:
```php
            'editStreetAddress' => 'nullable|string|max:255',
            'editStreetNumber' => 'nullable|string|max:50',
            'editPostalCode' => 'nullable|string|max:20',
            'editCity' => 'nullable|string|max:255',
```

In `save()`'s `$this->partner->update([...])` array, add after `'address' => $validated['editAddress'] ?: null,`:
```php
                'street_address' => $validated['editStreetAddress'] ?: null,
                'street_number' => $validated['editStreetNumber'] ?: null,
                'postal_code' => $validated['editPostalCode'] ?: null,
                'city' => $validated['editCity'] ?: null,
```

(The blade views for both forms are updated in Task 9 alongside the rest of the CompanyDashboard/PartnerShow UI rework — writing the PHP-side plumbing first keeps this task's test runnable without touching Blade.)

- [ ] **Step 6: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardStructuredAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_admin_can_save_structured_address_fields(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create();

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editStreetAddress', 'Мајка Тереза')
            ->set('editStreetNumber', '12')
            ->set('editPostalCode', '1000')
            ->set('editCity', 'Скопје')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame('Мајка Тереза', $company->street_address);
        $this->assertSame('12', $company->street_number);
        $this->assertSame('1000', $company->postal_code);
        $this->assertSame('Скопје', $company->city);
    }
}
```

Save as `tests/Feature/CompanyDashboardStructuredAddressTest.php`.

- [ ] **Step 7: Run the test and confirm it fails**

Run:
```powershell
php artisan test --filter CompanyDashboardStructuredAddressTest
```
Expected: FAIL (columns/properties don't exist yet if you run this before Steps 1–5; if run after, skip straight to Step 8's pass).

- [ ] **Step 8: Run the test and confirm it passes**

Run:
```powershell
php artisan test --filter CompanyDashboardStructuredAddressTest
```
Expected: `OK (1 test, ...)`.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_01_090000_add_structured_address_to_companies_and_partners_table.php app/Models/Company.php app/Models/Partner.php app/Livewire/CompanyDashboard.php app/Livewire/PartnerShow.php tests/Feature/CompanyDashboardStructuredAddressTest.php
git commit -m "feat: add structured street/postal/city address fields to companies and partners"
```

---

### Task 6: Migration + model — replace certificate-file storage with public signing-device metadata

**Files:**
- Create: `database/migrations/2026_08_01_090100_replace_efaktura_certificate_storage_with_signing_device_metadata.php`
- Modify: `app/Models/Company.php`
- Test: `tests/Unit/CompanyEfakturaAccessTest.php` (existing file — update, don't replace)

**Interfaces:**
- Produces: `companies.efaktura_token_serial_number` (string, nullable), `efaktura_token_subject_name` (string, nullable), `efaktura_token_not_before`/`efaktura_token_not_after` (timestamp, nullable), `efaktura_token_registered_at` (timestamp, nullable). Drops `efaktura_certificate_path`, `efaktura_certificate_password`. `Company::hasEfakturaAccess()` for `own` mode now checks the token metadata instead of the (removed) cert file. Consumed by `CompanyDashboard::registerSigningDevice()` (Task 8) and `EfakturaSendController` (Task 15).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('efaktura_token_serial_number')->nullable()->after('efaktura_certificate_password');
            $table->string('efaktura_token_subject_name')->nullable()->after('efaktura_token_serial_number');
            $table->timestamp('efaktura_token_not_before')->nullable()->after('efaktura_token_subject_name');
            $table->timestamp('efaktura_token_not_after')->nullable()->after('efaktura_token_not_before');
            $table->timestamp('efaktura_token_registered_at')->nullable()->after('efaktura_token_not_after');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['efaktura_certificate_path', 'efaktura_certificate_password']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('efaktura_certificate_path')->nullable()->after('efaktura_eujp_id');
            $table->text('efaktura_certificate_password')->nullable()->after('efaktura_certificate_path');
            $table->dropColumn([
                'efaktura_token_serial_number', 'efaktura_token_subject_name',
                'efaktura_token_not_before', 'efaktura_token_not_after', 'efaktura_token_registered_at',
            ]);
        });
    }
};
```

Save as `database/migrations/2026_08_01_090100_replace_efaktura_certificate_storage_with_signing_device_metadata.php`.

- [ ] **Step 2: Run the migration**

Run:
```powershell
php artisan migrate
```
Expected: runs successfully. (Local dev DB has no real certificate data to preserve — production's one real company already has null cert fields per project memory, so there's nothing to migrate forward.)

- [ ] **Step 3: Update `Company` model**

In `app/Models/Company.php`, change `$fillable`:
```php
    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
        'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
        'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_certificate_path',
        'efaktura_certificate_password', 'efaktura_firm_access_status',
        'efaktura_firm_access_decided_by', 'efaktura_firm_access_decided_at',
    ];
```
to:
```php
    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
        'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
        'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_firm_access_status',
        'efaktura_firm_access_decided_by', 'efaktura_firm_access_decided_at',
        'efaktura_token_serial_number', 'efaktura_token_subject_name',
        'efaktura_token_not_before', 'efaktura_token_not_after', 'efaktura_token_registered_at',
    ];
```

Change `casts()`:
```php
    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'efaktura_certificate_path' => 'encrypted',
            'efaktura_certificate_password' => 'encrypted',
        ];
    }
```
to:
```php
    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'efaktura_token_not_before' => 'datetime',
            'efaktura_token_not_after' => 'datetime',
            'efaktura_token_registered_at' => 'datetime',
        ];
    }
```

Change `hasEfakturaAccess()`:
```php
    public function hasEfakturaAccess(): bool
    {
        if ($this->efaktura_credential_mode === self::EFAKTURA_MODE_OWN) {
            return filled($this->efaktura_eujp_id)
                && filled($this->efaktura_certificate_path)
                && filled($this->efaktura_certificate_password);
        }

        return $this->efaktura_firm_access_status === self::EFAKTURA_STATUS_APPROVED;
    }
```
to:
```php
    public function hasEfakturaAccess(): bool
    {
        if ($this->efaktura_credential_mode === self::EFAKTURA_MODE_OWN) {
            return filled($this->efaktura_eujp_id) && filled($this->efaktura_token_serial_number);
        }

        return $this->efaktura_firm_access_status === self::EFAKTURA_STATUS_APPROVED;
    }
```

- [ ] **Step 4: Rewrite the existing unit test file**

`tests/Unit/CompanyEfakturaAccessTest.php` currently has 5 test methods. The first two (`test_defaults_to_firm_mode_with_no_access`, `test_firm_mode_has_access_only_once_approved`) are untouched by this task — `firm`-mode access logic didn't change. The next two (`test_own_mode_has_access_only_with_eujp_id_and_certificate`, `test_own_mode_without_certificate_password_has_no_access`) need their certificate columns swapped for the token column. The fifth (`test_certificate_path_and_password_are_encrypted_at_rest`) tests an `encrypted` cast on columns that no longer exist at all — delete it outright, there's no equivalent to write in its place (there's nothing left to encrypt; the token metadata is public data by design). Replace the whole file with:

```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEfakturaAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_firm_mode_with_no_access(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->efaktura_credential_mode);
        $this->assertSame(Company::EFAKTURA_STATUS_NONE, $company->efaktura_firm_access_status);
        $this->assertFalse($company->hasEfakturaAccess());
    }

    public function test_firm_mode_has_access_only_once_approved(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED,
        ]);

        $this->assertFalse($company->hasEfakturaAccess());

        $company->update(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_APPROVED]);

        $this->assertTrue($company->fresh()->hasEfakturaAccess());
    }

    public function test_own_mode_has_access_only_with_eujp_id_and_registered_device(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN]);

        $this->assertFalse($company->hasEfakturaAccess());

        $company->update([
            'efaktura_eujp_id' => 'EUJP-123',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $this->assertTrue($company->fresh()->hasEfakturaAccess());
    }

    public function test_own_mode_without_registered_device_has_no_access(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-123',
        ]);

        $this->assertFalse($company->fresh()->hasEfakturaAccess());
    }
}
```

Save as `tests/Unit/CompanyEfakturaAccessTest.php` (full replacement, not an incremental edit — the 5th method has no successor).

- [ ] **Step 5: Run the test and confirm it passes**

Run:
```powershell
php artisan test --filter CompanyEfakturaAccessTest
```
Expected: `OK (... tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_01_090100_replace_efaktura_certificate_storage_with_signing_device_metadata.php app/Models/Company.php tests/Unit/CompanyEfakturaAccessTest.php
git commit -m "feat: replace stored e-invoice certificate with public signing-device metadata"
```

Note: `tests/Feature/CompanyDashboardEfakturaCredentialsTest.php` (the Phase 8a cert-upload test suite) still references `newEfakturaCertificate`/`editEfakturaCertificatePassword`/`efaktura_certificate_path` — it will fail to compile once Task 8 removes those `CompanyDashboard` properties. Task 8 deletes this file and replaces it with `tests/Feature/CompanyDashboardSigningDeviceTest.php`; leave it alone here so this task's diff stays focused on the model/migration.

---

### Task 7: Migration + model — payment type and е-Фактура send tracking on sales invoices

**Files:**
- Create: `database/migrations/2026_08_01_090200_add_efaktura_send_tracking_to_sales_invoices_table.php`
- Modify: `app/Models/SalesInvoice.php`

**Interfaces:**
- Produces: `sales_invoices.payment_type_code` (string(3), default `P12`), `efaktura_status` (string, default `not_sent`), `efaktura_doc_id` (string, nullable), `efaktura_sent_at` (timestamp, nullable), `efaktura_error` (text, nullable). `SalesInvoice::PAYMENT_TYPES` const map, consumed by Task 10's form dropdown and Task 13's `EfakturaDocumentBuilder`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('payment_type_code', 3)->default('P12')->after('status');
            $table->string('efaktura_status', 20)->default('not_sent')->after('sent_at');
            $table->string('efaktura_doc_id')->nullable()->after('efaktura_status');
            $table->timestamp('efaktura_sent_at')->nullable()->after('efaktura_doc_id');
            $table->text('efaktura_error')->nullable()->after('efaktura_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_type_code', 'efaktura_status', 'efaktura_doc_id', 'efaktura_sent_at', 'efaktura_error']);
        });
    }
};
```

Save as `database/migrations/2026_08_01_090200_add_efaktura_send_tracking_to_sales_invoices_table.php`.

- [ ] **Step 2: Run the migration**

Run:
```powershell
php artisan migrate
```
Expected: runs successfully.

- [ ] **Step 3: Update `SalesInvoice` model**

In `app/Models/SalesInvoice.php`, add the payment-type map as a public const and extend `$fillable` and `casts()`:

```php
class SalesInvoice extends Model
{
    use HasFactory;
    use HasInvoiceTotals;

    public const PAYMENT_TYPES = [
        'P10' => 'Готово',
        'P11' => 'Картичка',
        'P12' => 'Плаќање преку банка',
        'P13' => 'Рати',
        'P14' => 'Онлајн-банка',
        'P15' => 'Мобилна апликација',
        'P16' => 'Без надомест',
        'P17' => 'Компензација',
        'P18' => 'Ваучер',
        'P19' => 'Друго',
    ];

    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'fiscal_year', 'invoice_number', 'invoice_date', 'due_date',
        'status', 'payment_type_code', 'sent_at', 'notes', 'created_by',
        'efaktura_status', 'efaktura_doc_id', 'efaktura_sent_at', 'efaktura_error',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'efaktura_sent_at' => 'datetime',
        ];
    }
```

(Everything else in the file — the relationship methods — is unchanged.)

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_01_090200_add_efaktura_send_tracking_to_sales_invoices_table.php app/Models/SalesInvoice.php
git commit -m "feat: add payment_type_code and e-invoice send-tracking columns to sales invoices"
```

(No dedicated test here — `payment_type_code` gets exercised by Task 10's form test, and the `efaktura_*` tracking columns by Task 15/17's send-flow tests.)

---

### Task 8: CompanyDashboard — replace certificate upload with "Регистрирај потпишувачки уред"

**Files:**
- Modify: `app/Livewire/CompanyDashboard.php`
- Modify: `resources/views/livewire/company-dashboard.blade.php`
- Delete: `tests/Feature/CompanyDashboardEfakturaCredentialsTest.php`
- Create: `tests/Feature/CompanyDashboardSigningDeviceTest.php`

**Interfaces:**
- Consumes: `Company::EFAKTURA_MODE_OWN`, the `efaktura_token_*` columns (Task 6).
- Produces: `CompanyDashboard::registerSigningDevice(string $serialNumber, string $subjectName, string $notBefore, string $notAfter): void` — a Livewire action called directly from the page's JS via `$wire.registerSigningDevice(...)` (Livewire 3's JS-to-PHP call, returns a promise) once the browser has fetched `/certificate` from the local bridge. No new HTTP route needed for this — it's the same mechanism Livewire already uses for every other button on this page.

- [ ] **Step 1: Remove the certificate-upload properties and logic from `CompanyDashboard`**

In `app/Livewire/CompanyDashboard.php`:

Remove these two properties:
```php
    public $newEfakturaCertificate = null;

    public string $editEfakturaCertificatePassword = '';
```

In `startEdit()`, remove:
```php
        $this->editEfakturaCertificatePassword = '';
        $this->newEfakturaCertificate = null;
```

In `save()`'s validation array, remove:
```php
            'newEfakturaCertificate' => ['nullable', 'file', 'max:5120', 'extensions:p12,pfx'],
            'editEfakturaCertificatePassword' => 'nullable|string|max:255',
```

Replace the whole own-mode-required manual check block:
```php
        if ($validated['editEfakturaMode'] === Company::EFAKTURA_MODE_OWN) {
            if (blank($validated['editEfakturaEujpId'])) {
                $this->addError('editEfakturaEujpId', 'X-EUJP-ID е задолжителен за сопствен е-Фактура пристап.');
            }
            if (! $this->newEfakturaCertificate && blank($this->company->efaktura_certificate_path)) {
                $this->addError('newEfakturaCertificate', 'Мора да прикачиш сертификат за сопствен е-Фактура пристап.');
            }
            if (blank($validated['editEfakturaCertificatePassword']) && blank($this->company->efaktura_certificate_password)) {
                $this->addError('editEfakturaCertificatePassword', 'Лозинката за сертификатот е задолжителна за сопствен е-Фактура пристап.');
            }

            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
        }

        if ($this->newEfakturaCertificate) {
            $password = $validated['editEfakturaCertificatePassword'] ?: $this->company->efaktura_certificate_password;
            $contents = file_get_contents($this->newEfakturaCertificate->getRealPath());
            if (! openssl_pkcs12_read($contents, $certs, (string) $password)) {
                $this->addError('newEfakturaCertificate', 'Сертификатот не може да се отвори со дадената лозинка — провери дали фајлот и лозинката се точни.');

                return;
            }
        }
```
with:
```php
        if ($validated['editEfakturaMode'] === Company::EFAKTURA_MODE_OWN && blank($validated['editEfakturaEujpId'])) {
            $this->addError('editEfakturaEujpId', 'X-EUJP-ID е задолжителен за сопствен е-Фактура пристап.');

            return;
        }
```

Replace the `$companyData` block that stores cert path/password:
```php
            if ($validated['editEfakturaMode'] === Company::EFAKTURA_MODE_OWN) {
                if (filled($validated['editEfakturaEujpId'])) {
                    $companyData['efaktura_eujp_id'] = $validated['editEfakturaEujpId'];
                }
                if ($this->newEfakturaCertificate) {
                    $companyData['efaktura_certificate_path'] = $this->newEfakturaCertificate
                        ->store('efaktura-certs/'.$this->company->id, 'local');
                }
                if (filled($validated['editEfakturaCertificatePassword'])) {
                    $companyData['efaktura_certificate_password'] = $validated['editEfakturaCertificatePassword'];
                }
            } else {
                $companyData['efaktura_eujp_id'] = null;
                $companyData['efaktura_certificate_path'] = null;
                $companyData['efaktura_certificate_password'] = null;
            }
```
with:
```php
            if ($validated['editEfakturaMode'] === Company::EFAKTURA_MODE_OWN) {
                if (filled($validated['editEfakturaEujpId'])) {
                    $companyData['efaktura_eujp_id'] = $validated['editEfakturaEujpId'];
                }
            } else {
                $companyData['efaktura_eujp_id'] = null;
            }
```

Also remove the now-unused `use Illuminate\Support\Facades\DB;`? — no, `DB::transaction` is still used elsewhere in `save()`, keep it. Remove `use Livewire\WithFileUploads;` **only if** `$newLogo` (a different, still-used file upload) doesn't need it — it does (logo upload stays), so **keep** `WithFileUploads`.

- [ ] **Step 2: Add `registerSigningDevice()`**

Add this new method to `CompanyDashboard` (anywhere after `requestFirmEfakturaAccess()` is a good spot). This deliberately does **not** call `Gate::authorize('update', $this->company)` — that policy is admin-only (`CompanyPolicy::update()`), which would block accountants from registering a device even though the design says signing is firm-staff work (admin **and** accountant). Reusing the broader `visibleCompanies()` check (the same one `CompanyPolicy::view()` already uses) keeps `CompanyPolicy::update()`'s admin-only meaning intact for the rest of the profile-edit form (name, tax ID, etc.) while still letting accountants register a device:

```php
    public function registerSigningDevice(string $serialNumber, string $subjectName, string $notBefore, string $notAfter): void
    {
        abort_unless(
            auth()->user()->hasAnyRole(['admin', 'accountant'])
                && auth()->user()->visibleCompanies()->whereKey($this->company->id)->exists(),
            403
        );

        if (blank($serialNumber)) {
            $this->addError('signingDevice', 'Не е добиен сериски број од токенот.');

            return;
        }

        $this->company->update([
            'efaktura_token_serial_number' => $serialNumber,
            'efaktura_token_subject_name' => $subjectName,
            'efaktura_token_not_before' => $notBefore,
            'efaktura_token_not_after' => $notAfter,
            'efaktura_token_registered_at' => now(),
        ]);
    }
```

Confirm `visibleCompanies()` is the exact method name on `App\Models\User` before typing this in (it's the same one used by `CompanyPolicy::view()` and `SalesInvoicePolicy::view()`, both read earlier in this plan's research) — if the real method has a different name, use that name here instead.

- [ ] **Step 3: Update the Blade view — remove the cert-upload block, add the signing-device card**

In `resources/views/livewire/company-dashboard.blade.php`, replace the "е-Фактура акредитиви" block (the `@if ($editEfakturaMode === 'own') ... @endif` section that currently has the file input) — remove the certificate/password inputs entirely, keeping only the EUJP-ID field:

```blade
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">е-Фактура акредитиви</h3>
                        <div class="flex gap-4 mb-3">
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" wire:model="editEfakturaMode" value="firm">
                                <span>Користи го фирменото</span>
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" wire:model="editEfakturaMode" value="own">
                                <span>Сопствени акредитиви</span>
                            </label>
                        </div>

                        @if ($editEfakturaMode === 'own')
                            <div>
                                <label class="block text-sm text-gray-600 mb-1">X-EUJP-ID</label>
                                <input type="text" wire:model="editEfakturaEujpId" class="w-full rounded-lg border-gray-300">
                                @error('editEfakturaEujpId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                <p class="text-xs text-gray-500 mt-1">Потпишувачкиот уред (USB токен) се регистрира одделно, подолу на страницата — не преку овој формулар.</p>
                            </div>
                        @endif
                    </div>
```

Then add structured address inputs next to the existing `editAddress` field — replace:
```blade
                        <div class="sm:col-span-2">
                            <x-input-label for="editAddress" value="Адреса" />
                            <x-text-input id="editAddress" wire:model="editAddress" class="w-full" />
                        </div>
```
with:
```blade
                        <div class="sm:col-span-2">
                            <x-input-label for="editAddress" value="Адреса (слободен текст)" />
                            <x-text-input id="editAddress" wire:model="editAddress" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editStreetAddress" value="Улица (за е-Фактура)" />
                            <x-text-input id="editStreetAddress" wire:model="editStreetAddress" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editStreetNumber" value="Број" />
                            <x-text-input id="editStreetNumber" wire:model="editStreetNumber" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editPostalCode" value="Поштенски број" />
                            <x-text-input id="editPostalCode" wire:model="editPostalCode" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="editCity" value="Град" />
                            <x-text-input id="editCity" wire:model="editCity" class="w-full" />
                        </div>
```

Finally, add a new card **before** the `@can('update', $company)` edit-form block (near the existing "е-Фактура пристап" card at the top of the file), visible to admin/accountant regardless of whether the edit form is open:

```blade
    @if (auth()->user()->hasAnyRole(['admin', 'accountant']))
        <x-card class="mb-6" x-data="signingDeviceRegistration()">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Потпишувачки уред (USB токен)</h3>

            @if ($company->efaktura_token_serial_number)
                <p class="text-sm text-gray-600 mb-1">Регистриран: <span class="font-medium">{{ $company->efaktura_token_subject_name }}</span> (сериски бр. {{ $company->efaktura_token_serial_number }})</p>
                <p class="text-xs text-gray-500 mb-3">Важи до {{ optional($company->efaktura_token_not_after)->format('d.m.Y') }}</p>
            @else
                <p class="text-sm text-gray-500 mb-3">Нема регистриран потпишувачки уред за оваа компанија.</p>
            @endif

            <div class="flex items-center gap-3">
                <button type="button" @click="check()" :disabled="busy" class="rounded-full bg-gray-100 text-gray-700 px-4 py-2 text-sm disabled:opacity-50">
                    <span x-show="!busy">Провери токен</span>
                    <span x-show="busy">Читам...</span>
                </button>
                <a href="{{ asset('downloads/efaktura-bridge/EfakturaBridge.Server.exe') }}" class="text-brand hover:underline text-sm">Преземи локален потпишувач</a>
            </div>

            <div x-show="detected" class="mt-3 border rounded-lg p-3 bg-gray-50">
                <p class="text-sm">Пронајден: <span x-text="subjectName" class="font-medium"></span></p>
                <p class="text-xs text-gray-500">Сериски бр. <span x-text="serialNumber"></span>, важи до <span x-text="notAfter"></span></p>
                <button type="button" @click="confirmRegister()" class="mt-2 rounded-full bg-brand text-white px-4 py-1.5 text-sm">Потврди — ова е точниот уред</button>
            </div>

            <p x-show="error" x-text="error" class="text-red-600 text-sm mt-2"></p>
        </x-card>

        @script
        <script>
            Alpine.data('signingDeviceRegistration', () => ({
                busy: false,
                detected: false,
                error: '',
                serialNumber: '',
                subjectName: '',
                notBefore: '',
                notAfter: '',
                async check() {
                    this.busy = true; this.error = ''; this.detected = false;
                    try {
                        const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
                        if (!health || !health.ok) {
                            throw new Error('Локалниот потпишувач не работи. Стартувај го (преземи го копчето погоре) и обиди се повторно.');
                        }
                        const certRes = await fetch('http://127.0.0.1:9847/certificate');
                        if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот — провери дали е приклучен.');
                        const cert = await certRes.json();
                        this.serialNumber = cert.serialNumber;
                        this.subjectName = cert.subjectName;
                        this.notBefore = cert.notBefore;
                        this.notAfter = cert.notAfter;
                        this.detected = true;
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.busy = false;
                    }
                },
                async confirmRegister() {
                    await $wire.registerSigningDevice(this.serialNumber, this.subjectName, this.notBefore, this.notAfter);
                    this.detected = false;
                },
            }));
        </script>
        @endscript
    @endif

```

- [ ] **Step 4: Delete the obsolete cert-upload test file**

```bash
git rm tests/Feature/CompanyDashboardEfakturaCredentialsTest.php
```

- [ ] **Step 5: Write the new signing-device test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardSigningDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_admin_can_register_a_signing_device(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create();

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z');

        $company->refresh();
        $this->assertSame('1A2B3C', $company->efaktura_token_serial_number);
        $this->assertSame('CN=Test Company', $company->efaktura_token_subject_name);
        $this->assertNotNull($company->efaktura_token_registered_at);
    }

    public function test_accountant_can_register_a_signing_device(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company = Company::factory()->create();
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z');

        $this->assertSame('1A2B3C', $company->fresh()->efaktura_token_serial_number);
    }

    public function test_client_cannot_register_a_signing_device(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('registerSigningDevice', '1A2B3C', 'CN=Test Company', '2025-01-01T00:00:00Z', '2027-01-01T00:00:00Z')
            ->assertForbidden();

        $this->assertNull($company->fresh()->efaktura_token_serial_number);
    }

    public function test_switching_to_own_mode_without_eujp_id_fails_validation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_OWN)
            ->call('save')
            ->assertHasErrors(['editEfakturaEujpId']);

        $this->assertSame(Company::EFAKTURA_MODE_FIRM, $company->fresh()->efaktura_credential_mode);
    }
}
```

Save as `tests/Feature/CompanyDashboardSigningDeviceTest.php`.

**Note on `test_accountant_can_register_a_signing_device`:** it attaches the accountant via `Company::accountants()` (the existing `belongsToMany(User::class)` relation in `app/Models/Company.php`) specifically so `visibleCompanies()` — whatever its real join/query looks like — has a legitimate row to find. If that test still fails after Step 2's implementation, read `app/Models/User.php`'s `visibleCompanies()` method to see what relation it actually queries and adjust the test's setup (not the guard logic) to match.

- [ ] **Step 6: Run the tests and confirm they pass**

Run:
```powershell
php artisan test --filter CompanyDashboardSigningDeviceTest
php artisan test --filter CompanyDashboardStructuredAddressTest
php artisan test --filter CompanyDashboardTest
```
Expected: all `OK`.

- [ ] **Step 7: Manual smoke check in the browser**

Start the app's dev server if not already running, log in as admin, open a company dashboard, confirm: the old "Сертификат (.p12/.pfx)" file input is gone, the new "Потпишувачки уред" card renders with "Нема регистриран потпишувачки уред", and the four new address fields appear in the edit form. (Clicking "Провери токен" won't succeed without the real bridge+token running — that's expected; this check is purely about the UI rendering correctly.)

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/CompanyDashboard.php resources/views/livewire/company-dashboard.blade.php tests/Feature/CompanyDashboardSigningDeviceTest.php
git rm tests/Feature/CompanyDashboardEfakturaCredentialsTest.php
git commit -m "feat: replace certificate upload with signing-device registration UI"
```

---

### Task 9: PartnerShow — add structured address fields to the view

**Files:**
- Modify: `resources/views/livewire/partner-show.blade.php`

**Interfaces:**
- Consumes: `editStreetAddress`/`editStreetNumber`/`editPostalCode`/`editCity` properties added to `PartnerShow` in Task 5.

Task 5 already wired the PHP side (`PartnerShow`'s properties, `startEdit()`, `save()`). This task only touches the Blade view.

- [ ] **Step 1: Find and update the address input in the view**

Read `resources/views/livewire/partner-show.blade.php`, find the block rendering `editAddress` (structurally the same pattern as `CompanyDashboard`'s — an `<x-input-label>` + `<x-text-input wire:model="editAddress">` pair inside the edit form), and add four new fields immediately after it, following the exact same markup style already used in that file for the other edit-form fields:

```blade
                <div>
                    <x-input-label for="editStreetAddress" value="Улица (за е-Фактура)" />
                    <x-text-input id="editStreetAddress" wire:model="editStreetAddress" class="w-full" />
                </div>
                <div>
                    <x-input-label for="editStreetNumber" value="Број" />
                    <x-text-input id="editStreetNumber" wire:model="editStreetNumber" class="w-full" />
                </div>
                <div>
                    <x-input-label for="editPostalCode" value="Поштенски број" />
                    <x-text-input id="editPostalCode" wire:model="editPostalCode" class="w-full" />
                </div>
                <div>
                    <x-input-label for="editCity" value="Град" />
                    <x-text-input id="editCity" wire:model="editCity" class="w-full" />
                </div>
```

- [ ] **Step 2: Run the existing PartnerShow tests to confirm no regression**

Run:
```powershell
php artisan test --filter PartnerShow
```
Expected: `OK`.

- [ ] **Step 3: Manual smoke check**

Open a partner's page as admin, click "Уреди", confirm the four new fields render and save correctly (fill them in, save, reopen edit, confirm values persisted).

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/partner-show.blade.php
git commit -m "feat: show structured address fields in the partner edit form"
```

---

### Task 10: SalesInvoiceForm — payment type dropdown

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceForm.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-form.blade.php`
- Test: `tests/Feature/SalesInvoiceFormPaymentTypeTest.php`

**Interfaces:**
- Consumes: `SalesInvoice::PAYMENT_TYPES` (Task 7).
- Produces: `SalesInvoiceForm::$paymentTypeCode` — persisted onto the invoice, consumed by `EfakturaDocumentBuilder` (Task 13).

- [ ] **Step 1: Add the property and default**

In `app/Livewire/Invoicing/SalesInvoiceForm.php`, add after `public string $notes = '';`:
```php
    public string $paymentTypeCode = 'P12';
```

In `mount()`, in the `if ($salesInvoice)` branch, add after `$this->notes = (string) $salesInvoice->notes;`:
```php
            $this->paymentTypeCode = $salesInvoice->payment_type_code;
```
(The `else` branch already leaves `$this->paymentTypeCode` at its default `'P12'`, so no change needed there.)

- [ ] **Step 2: Validate and persist it**

In `save()`'s `$this->validate([...])` array, add after `'dueDate' => 'required|date|after_or_equal:invoiceDate',`:
```php
            'paymentTypeCode' => ['required', Rule::in(array_keys(SalesInvoice::PAYMENT_TYPES))],
```
(`SalesInvoiceForm.php` already imports `use App\Models\SalesInvoice;`, so no new import is needed.)

In `save()`'s `DB::transaction` closure, add after `$invoice->notes = $this->notes ?: null;`:
```php
            $invoice->payment_type_code = $this->paymentTypeCode;
```

- [ ] **Step 3: Add the dropdown to the view**

In `resources/views/livewire/invoicing/sales-invoice-form.blade.php`, find the block rendering the `notes` textarea (or the invoice-date/due-date fields — wherever header-level, non-line fields are grouped) and add nearby:

```blade
<div>
    <x-input-label for="paymentTypeCode" value="Начин на плаќање" />
    <select id="paymentTypeCode" wire:model="paymentTypeCode" class="w-full rounded-lg border-gray-300 text-sm">
        @foreach (\App\Models\SalesInvoice::PAYMENT_TYPES as $code => $label)
            <option value="{{ $code }}">{{ $label }}</option>
        @endforeach
    </select>
    @error('paymentTypeCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
</div>
```

Read the file first to place this consistently with the existing header-field markup (label/input wrapper classes) rather than guessing exact classes.

- [ ] **Step 4: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceFormPaymentTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_defaults_to_p12_and_can_be_changed(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(SalesInvoiceForm::class, ['company' => $company]);
        $this->assertSame('P12', $component->get('paymentTypeCode'));

        $component
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('paymentTypeCode', 'P10')
            ->set('lines.0.description', 'Test line')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '100')
            ->call('save');

        $invoice = SalesInvoice::first();
        $this->assertSame('P10', $invoice->payment_type_code);
    }
}
```

Save as `tests/Feature/SalesInvoiceFormPaymentTypeTest.php`.

- [ ] **Step 5: Run the test and confirm it fails, then passes**

Run:
```powershell
php artisan test --filter SalesInvoiceFormPaymentTypeTest
```
Expected: FAIL before Steps 1–3, `OK` after.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceForm.php resources/views/livewire/invoicing/sales-invoice-form.blade.php tests/Feature/SalesInvoiceFormPaymentTypeTest.php
git commit -m "feat: add payment type dropdown to the sales invoice form"
```

---

### Task 11: `App\Support\Base64Url` — PHP base64url helper

**Files:**
- Create: `app/Support/Base64Url.php`
- Test: `tests/Unit/Support/Base64UrlTest.php`

**Interfaces:**
- Produces: `App\Support\Base64Url::encode(string $data): string`, `App\Support\Base64Url::decode(string $value): string` — RFC 4648 §5 base64url, no padding. Consumed by `EfakturaJwsService` (Task 14).

- [ ] **Step 1: Write the failing test**

```php
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
```

Save as `tests/Unit/Support/Base64UrlTest.php`.

- [ ] **Step 2: Run the test and confirm it fails**

Run:
```powershell
php artisan test --filter Base64UrlTest
```
Expected: FAIL — `Class "App\Support\Base64Url" not found`.

- [ ] **Step 3: Implement `Base64Url`**

```php
<?php

namespace App\Support;

class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
```

Save as `app/Support/Base64Url.php`.

- [ ] **Step 4: Run the test and confirm it passes**

Run:
```powershell
php artisan test --filter Base64UrlTest
```
Expected: `OK (4 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Base64Url.php tests/Unit/Support/Base64UrlTest.php
git commit -m "feat: add Base64Url helper for JWS assembly"
```

---

### Task 12: `EfakturaTaxIndicator` — vat_treatment+rate → UJP tax-indicator code

**Files:**
- Create: `app/Services/Efaktura/EfakturaTaxIndicator.php`
- Test: `tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php`

**Interfaces:**
- Produces: `App\Services\Efaktura\EfakturaTaxIndicator::code(string $vatTreatment, string $vatRate): string` and `::percent(string $vatTreatment, string $vatRate): float`. Consumed by `EfakturaDocumentBuilder` (Task 13).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Efaktura;

use App\Services\Efaktura\EfakturaTaxIndicator;
use PHPUnit\Framework\TestCase;

class EfakturaTaxIndicatorTest extends TestCase
{
    public function test_standard_18_percent_maps_to_ddv_a(): void
    {
        $this->assertSame('DDV-A', EfakturaTaxIndicator::code('standard', '18.00'));
        $this->assertSame(18.0, EfakturaTaxIndicator::percent('standard', '18.00'));
    }

    public function test_standard_10_percent_maps_to_ddv_v(): void
    {
        $this->assertSame('DDV-V', EfakturaTaxIndicator::code('standard', '10.00'));
        $this->assertSame(10.0, EfakturaTaxIndicator::percent('standard', '10.00'));
    }

    public function test_standard_5_percent_maps_to_ddv_b(): void
    {
        $this->assertSame('DDV-B', EfakturaTaxIndicator::code('standard', '5.00'));
        $this->assertSame(5.0, EfakturaTaxIndicator::percent('standard', '5.00'));
    }

    public function test_export_maps_to_ddv_7_i(): void
    {
        $this->assertSame('DDV-7-I', EfakturaTaxIndicator::code('export', '0.00'));
        $this->assertSame(0.0, EfakturaTaxIndicator::percent('export', '0.00'));
    }

    public function test_exempt_with_credit_maps_to_ddv_8(): void
    {
        $this->assertSame('DDV-8', EfakturaTaxIndicator::code('exempt_with_credit', '0.00'));
    }

    public function test_exempt_without_credit_maps_to_ddv_9(): void
    {
        $this->assertSame('DDV-9', EfakturaTaxIndicator::code('exempt_without_credit', '0.00'));
    }

    public function test_unknown_combination_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EfakturaTaxIndicator::code('standard', '7.00');
    }
}
```

Save as `tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php`.

- [ ] **Step 2: Run the test and confirm it fails**

Run:
```powershell
php artisan test --filter EfakturaTaxIndicatorTest
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `EfakturaTaxIndicator`**

```php
<?php

namespace App\Services\Efaktura;

class EfakturaTaxIndicator
{
    public static function code(string $vatTreatment, string $vatRate): string
    {
        return self::resolve($vatTreatment, $vatRate)[0];
    }

    public static function percent(string $vatTreatment, string $vatRate): float
    {
        return self::resolve($vatTreatment, $vatRate)[1];
    }

    /**
     * @return array{0: string, 1: float}
     */
    private static function resolve(string $vatTreatment, string $vatRate): array
    {
        return match (true) {
            $vatTreatment === 'standard' && bccomp($vatRate, '18.00', 2) === 0 => ['DDV-A', 18.0],
            $vatTreatment === 'standard' && bccomp($vatRate, '10.00', 2) === 0 => ['DDV-V', 10.0],
            $vatTreatment === 'standard' && bccomp($vatRate, '5.00', 2) === 0 => ['DDV-B', 5.0],
            $vatTreatment === 'export' => ['DDV-7-I', 0.0],
            $vatTreatment === 'exempt_with_credit' => ['DDV-8', 0.0],
            $vatTreatment === 'exempt_without_credit' => ['DDV-9', 0.0],
            default => throw new \InvalidArgumentException(
                "Нема познат УЈП даночен индикатор за третман='{$vatTreatment}', стапка='{$vatRate}'."
            ),
        };
    }
}
```

Save as `app/Services/Efaktura/EfakturaTaxIndicator.php`. Mapping source: `danocni_indikatori_27072026.pdf` v3 (27/07/2026), read directly during this plan's research — `DDV-A`/`DDV-V`/`DDV-B` for the three standard rates, `DDV-7-I` for export (член 24 став 1 точка 1), `DDV-8` for other credit-eligible exemptions (член 24 став 1, точки 2–10), `DDV-9` for exemptions without credit (член 23).

- [ ] **Step 4: Run the test and confirm it passes**

Run:
```powershell
php artisan test --filter EfakturaTaxIndicatorTest
```
Expected: `OK (7 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Efaktura/EfakturaTaxIndicator.php tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php
git commit -m "feat: map vat_treatment+rate to UJP tax-indicator codes"
```

---

### Task 13: `EfakturaDocumentBuilder` — build the UJP JSON document from a sales invoice

**Files:**
- Create: `app/Services/Efaktura/EfakturaDocumentBuilder.php`
- Test: `tests/Unit/Services/Efaktura/EfakturaDocumentBuilderTest.php`

**Interfaces:**
- Consumes: `EfakturaTaxIndicator::code()/percent()` (Task 12), `SalesInvoice::PAYMENT_TYPES` (Task 7), `SalesInvoiceLine::lineTotal()/vatAmount()` (existing), company/partner structured address fields (Task 5).
- Produces: `App\Services\Efaktura\EfakturaDocumentBuilder::build(SalesInvoice $invoice): array` — the full `document` payload (matching the shape confirmed in `primer_za_json_2.pdf` and already prototyped in the now-removed `efaktura:spike-send` command). Consumed by `EfakturaJwsService` (Task 14).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Efaktura;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaDocumentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EfakturaDocumentBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_document_with_seller_buyer_and_totals(): void
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'name' => 'Тест Фирма ДООЕЛ',
            'street_address' => 'Мајка Тереза', 'street_number' => '12',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'tax_id' => '4030007654321',
            'name' => 'Купувач ДОО',
            'street_address' => 'Партизанска', 'street_number' => '5',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'fiscal_year' => 2026,
            'invoice_number' => 42,
            'invoice_date' => '2026-03-01',
            'status' => 'confirmed',
            'payment_type_code' => 'P12',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга А', 'quantity' => '2', 'unit_price' => '100.00',
            'vat_rate' => '18.00', 'vat_treatment' => 'standard',
        ]);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $this->assertSame('2026-42', $document['document']['header']['docNumber']);
        $this->assertSame('4030001234567', $document['document']['seller']['sellerTin']);
        $this->assertSame('Мајка Тереза', $document['document']['seller']['sellerAddress']['streetAddress']);
        $this->assertSame('4030007654321', $document['document']['buyer']['buyerTin']);
        $this->assertSame('P12', $document['document']['docPayment']['docPaymentTypeCode']);
        $this->assertSame('Плаќање преку банка', $document['document']['docPayment']['docPaymentTypeDesc']);
        $this->assertCount(1, $document['document']['docItems']);
        $this->assertSame('DDV-A', $document['document']['docItems'][0]['docItemTaxIndicator']);
        $this->assertSame(200.0, $document['document']['docTotals']['docNetAmount']);
        $this->assertSame(36.0, $document['document']['docTotals']['docVatAmount']);
        $this->assertSame(236.0, $document['document']['docTotals']['docGrossAmount']);
        $this->assertCount(1, $document['document']['vatTotals']);
        $this->assertSame('DDV-A', $document['document']['vatTotals'][0]['vatTaxIndicator']);
        $this->assertSame(36.0, $document['document']['vatTotals'][0]['vatAmount']);
    }

    public function test_groups_vat_totals_by_distinct_tax_indicator(): void
    {
        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $invoice->lines()->create(['description' => 'B', 'quantity' => '1', 'unit_price' => '50.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $invoice->lines()->create(['description' => 'C', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        $document = (new EfakturaDocumentBuilder)->build($invoice->fresh(['lines', 'company', 'partner']));

        $this->assertCount(2, $document['document']['vatTotals']);
        $ddvA = collect($document['document']['vatTotals'])->firstWhere('vatTaxIndicator', 'DDV-A');
        $this->assertSame(150.0, $ddvA['vatTaxableAmount']);
        $this->assertSame(27.0, $ddvA['vatAmount']);
        $ddv7i = collect($document['document']['vatTotals'])->firstWhere('vatTaxIndicator', 'DDV-7-I');
        $this->assertSame(10.0, $ddv7i['vatTaxableAmount']);
        $this->assertSame(0.0, $ddv7i['vatAmount']);
    }
}
```

Save as `tests/Unit/Services/Efaktura/EfakturaDocumentBuilderTest.php`.

- [ ] **Step 2: Run the test and confirm it fails**

Run:
```powershell
php artisan test --filter EfakturaDocumentBuilderTest
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `EfakturaDocumentBuilder`**

```php
<?php

namespace App\Services\Efaktura;

use App\Models\SalesInvoice;
use App\Support\Bcmath;

class EfakturaDocumentBuilder
{
    public function build(SalesInvoice $invoice): array
    {
        $company = $invoice->company;
        $partner = $invoice->partner;
        $docNumber = "{$invoice->fiscal_year}-{$invoice->invoice_number}";
        $today = $invoice->invoice_date->toDateString();

        $items = $invoice->lines->values()->map(fn ($line, $index) => $this->buildItem($line, $index + 1))->all();

        $netAmount = array_sum(array_column($items, 'docItemTotalPriceWoVat'));
        $vatAmount = array_sum(array_column($items, 'docItemTotalVat'));
        $grossAmount = round($netAmount + $vatAmount, 2);

        return [
            'requestTimestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'document' => [
                'header' => [
                    'docStorno' => 0,
                    'docType' => '100',
                    'docTypeName' => 'Фактура',
                    'docDate' => $today,
                    'docTurnoverDate' => $today,
                    'docNumber' => $docNumber,
                    'docId' => $docNumber,
                    'docNotes' => $invoice->notes,
                    'docHeader' => null,
                    'docFooter' => null,
                ],
                'seller' => $this->buildParty($company->name, $company->tax_id, $company->street_address, $company->street_number, $company->postal_code, $company->city, 'seller'),
                'buyer' => $this->buildParty($partner->name, $partner->tax_id, $partner->street_address, $partner->street_number, $partner->postal_code, $partner->city, 'buyer'),
                'docPayment' => [
                    'docPaymentTypeCode' => $invoice->payment_type_code,
                    'docPaymentTypeDesc' => SalesInvoice::PAYMENT_TYPES[$invoice->payment_type_code] ?? $invoice->payment_type_code,
                    'docPaymentTypeDueDays' => null,
                    'docPaymentTypeDueDate' => null,
                    'docPaymentTerms' => null,
                    'docPaymentNote' => null,
                    'docPaymentInterest' => null,
                    'docPaymentDiscount' => null,
                    'docCurrency' => 'MKD',
                    'docCurrencyCode' => 'MKD',
                    'docCurrencyDate' => $today,
                    'docCurrencyExchRate' => 1,
                ],
                'docItems' => $items,
                'docTotals' => [
                    'docNetAmount' => $netAmount,
                    'docDiscountAmount' => 0,
                    'docNetAmountDisc' => $netAmount,
                    'docVatAmount' => $vatAmount,
                    'docGrossAmount' => $grossAmount,
                    'docGrossAmountR' => $grossAmount,
                    'docAvansAmount' => 0,
                    'docFinalAmount' => $grossAmount,
                ],
                'vatTotals' => $this->buildVatTotals($invoice),
            ],
        ];
    }

    private function buildParty(?string $name, ?string $taxId, ?string $streetAddress, ?string $streetNumber, ?string $postalCode, ?string $city, string $prefix): array
    {
        return [
            "{$prefix}CCode" => 'MK',
            "{$prefix}CName" => 'Северна Македонија',
            "{$prefix}Tin" => $taxId,
            "{$prefix}ForeignTin" => null,
            "{$prefix}VatNumber" => $taxId ? 'МК'.$taxId : null,
            "{$prefix}Name" => $name,
            "{$prefix}Address" => [
                'streetAddress' => $streetAddress ?? '',
                'streetNumber' => $streetNumber ?? '',
                'postalCode' => $postalCode ?? '',
                'city' => $city ?? '',
            ],
            "{$prefix}Contact" => null,
            "{$prefix}Email" => null,
        ];
    }

    private function buildItem($line, int $lineNo): array
    {
        $qty = (float) $line->quantity;
        $unitPrice = (float) $line->unit_price;
        $lineTotal = (float) $line->lineTotal();
        $vatAmount = (float) $line->vatAmount();
        $vatPercent = EfakturaTaxIndicator::percent($line->vat_treatment, (string) $line->vat_rate);
        $taxIndicator = EfakturaTaxIndicator::code($line->vat_treatment, (string) $line->vat_rate);

        return [
            'docItemLineNo' => $lineNo,
            'docItemSku' => $line->item?->code,
            'docItemSenderCode' => $line->item?->code,
            'docItemReceiverCode' => null,
            'docItemDesc' => $line->description ?: $line->item?->name,
            'docItemMUnit' => $line->item?->unit_of_measure ?: 'бр.',
            'docItemQty' => $qty,
            'docItemUnitOriginalPriceWoVat' => $unitPrice,
            'docItemUnitDiscountAmount' => 0,
            'docItemUnitPriceWoVat' => $unitPrice,
            'docItemUnitVat' => $vatPercent,
            'docItemVat' => $vatPercent,
            'docItemVatGroup' => $taxIndicator,
            'docItemTotalOriginalPriceWoVat' => $lineTotal,
            'docItemTotalPriceWoVat' => $lineTotal,
            'docItemTotalVat' => $vatAmount,
            'docItemTotalPriceWVat' => round($lineTotal + $vatAmount, 2),
            'docItemTaxIndicator' => $taxIndicator,
            'docItemDomesticProduct' => null,
        ];
    }

    private function buildVatTotals(SalesInvoice $invoice): array
    {
        return $invoice->lines
            ->groupBy(fn ($line) => EfakturaTaxIndicator::code($line->vat_treatment, (string) $line->vat_rate))
            ->map(function ($lines, $code) {
                $percent = EfakturaTaxIndicator::percent($lines->first()->vat_treatment, (string) $lines->first()->vat_rate);
                $base = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->lineTotal(), 10), '0');
                $vat = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->vatAmount(), 10), '0');
                $base = (float) Bcmath::roundHalfUp($base, 2);
                $vat = (float) Bcmath::roundHalfUp($vat, 2);

                return [
                    'vatTaxIndicator' => $code,
                    'vatTaxIndicatorNote' => '',
                    'vatCode' => $code,
                    'vatPercent' => $percent,
                    'vatTaxableAmount' => $base,
                    'vatAmount' => $vat,
                    'vatTotalAmount' => round($base + $vat, 2),
                ];
            })
            ->values()
            ->all();
    }
}
```

Save as `app/Services/Efaktura/EfakturaDocumentBuilder.php`.

- [ ] **Step 4: Run the test and confirm it passes**

Run:
```powershell
php artisan test --filter EfakturaDocumentBuilderTest
```
Expected: `OK (2 tests, ...)`. If a float-precision assertion fails (e.g. `36.0` vs `36.00000000001`), switch that specific assertion to `assertEqualsWithDelta($expected, $actual, 0.001)` rather than loosening the production rounding — the `Bcmath::roundHalfUp` calls already guarantee 2-decimal precision, so a mismatch more likely means an arithmetic mistake in the test's expected values than a real bug; re-check the math by hand before touching the assertion.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Efaktura/EfakturaDocumentBuilder.php tests/Unit/Services/Efaktura/EfakturaDocumentBuilderTest.php
git commit -m "feat: build the UJP e-invoice JSON document from a sales invoice"
```

---

### Task 14: `EfakturaJwsService` — signing-input assembly and UJP send

**Files:**
- Create: `app/Services/Efaktura/EfakturaJwsService.php`
- Test: `tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php`

**Interfaces:**
- Consumes: `EfakturaDocumentBuilder::build()` (Task 13), `App\Support\Base64Url` (Task 11).
- Produces: `EfakturaJwsService::buildSigningInput(SalesInvoice $invoice, string $certificateBase64Der): array{signingInput: string, payloadJson: string}` and `EfakturaJwsService::send(Company $company, string $signingInput, string $signatureBase64Url): \Illuminate\Http\Client\Response`. Consumed by `EfakturaSendController` (Task 15).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Efaktura;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaJwsService;
use App\Support\Base64Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EfakturaJwsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(): SalesInvoice
    {
        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        return $invoice->fresh(['lines', 'company', 'partner']);
    }

    public function test_signing_input_is_base64url_header_dot_base64url_payload(): void
    {
        $invoice = $this->makeInvoice();
        $certDer = base64_encode('fake-der-bytes');

        $result = (new EfakturaJwsService)->buildSigningInput($invoice, $certDer);

        [$headerPart, $payloadPart] = explode('.', $result['signingInput']);
        $header = json_decode(Base64Url::decode($headerPart), true);
        $payload = json_decode(Base64Url::decode($payloadPart), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame([$certDer], $header['x5c']);
        $this->assertSame('2026-1', $payload['document']['header']['docNumber']);
        $this->assertSame($result['payloadJson'], Base64Url::decode($payloadPart));
    }

    public function test_send_posts_compact_jws_with_expected_headers(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $service = new EfakturaJwsService;
        $response = $service->send($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(function ($request) {
            return $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/JSONReceiver/api/v1/sales-invoices/send'
                && $request->hasHeader('X-EUJP-ID', 'EUJP-1')
                && $request->hasHeader('X-EDB', '4030001234567')
                && $request->hasHeader('X-SERIAL-NUMBER', '1A2B3C')
                && $request->body() === 'header.payload.c2ln';
        });
    }
}
```

Save as `tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php`.

- [ ] **Step 2: Run the test and confirm it fails**

Run:
```powershell
php artisan test --filter EfakturaJwsServiceTest
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `EfakturaJwsService`**

```php
<?php

namespace App\Services\Efaktura;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Support\Base64Url;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class EfakturaJwsService
{
    public function __construct(private ?EfakturaDocumentBuilder $documentBuilder = null)
    {
        $this->documentBuilder ??= new EfakturaDocumentBuilder;
    }

    /**
     * @return array{signingInput: string, payloadJson: string}
     */
    public function buildSigningInput(SalesInvoice $invoice, string $certificateBase64Der): array
    {
        $payload = $this->documentBuilder->build($invoice);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $header = ['alg' => 'RS256', 'x5c' => [$certificateBase64Der]];
        $headerJson = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signingInput = Base64Url::encode($headerJson).'.'.Base64Url::encode($payloadJson);

        return ['signingInput' => $signingInput, 'payloadJson' => $payloadJson];
    }

    public function send(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        $compact = $signingInput.'.'.$signatureBase64Url;
        $baseUrl = config('services.efaktura.base_url');
        $url = rtrim($baseUrl, '/').'/JSONReceiver/api/v1/sales-invoices/send';

        return Http::withHeaders([
            'X-EUJP-ID' => $company->efaktura_eujp_id,
            'X-EDB' => $company->tax_id,
            'X-SERIAL-NUMBER' => $company->efaktura_token_serial_number,
        ])->withBody($compact, 'application/jose')->post($url);
    }
}
```

Save as `app/Services/Efaktura/EfakturaJwsService.php`.

- [ ] **Step 4: Run the test and confirm it passes**

Run:
```powershell
php artisan test --filter EfakturaJwsServiceTest
```
Expected: `OK (2 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Efaktura/EfakturaJwsService.php tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php
git commit -m "feat: assemble JWS signing-input and send compact JWS to UJP"
```

---

### Task 15: `EfakturaSendController` — the two new Laravel routes

**Files:**
- Create: `app/Http/Controllers/EfakturaSendController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EfakturaSendControllerTest.php`

**Interfaces:**
- Consumes: `EfakturaJwsService` (Task 14), `Cache` facade (`database` store, already configured).
- Produces: `POST companies/{company}/sales-invoices/{salesInvoice}/efaktura/signing-input` (route name `sales-invoices.efaktura.signing-input`) and `POST companies/{company}/sales-invoices/{salesInvoice}/efaktura/send` (route name `sales-invoices.efaktura.send`). Consumed by Task 17's browser JS.

- [ ] **Step 1: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaSendController extends Controller
{
    public function signingInput(Request $request, Company $company, SalesInvoice $salesInvoice, EfakturaJwsService $jwsService)
    {
        $this->authorizeSigning($company, $salesInvoice);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $result = $jwsService->buildSigningInput($salesInvoice, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-signing:{$token}", [
            'company_id' => $company->id,
            'sales_invoice_id' => $salesInvoice->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function send(Request $request, Company $company, SalesInvoice $salesInvoice, EfakturaJwsService $jwsService)
    {
        $this->authorizeSigning($company, $salesInvoice);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-signing:{$validated['token']}");

        if (! $cached || $cached['company_id'] !== $company->id || $cached['sales_invoice_id'] !== $salesInvoice->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        $response = $jwsService->send($company, $cached['signing_input'], $validated['signature']);

        if (! $response->successful()) {
            $salesInvoice->update(['efaktura_status' => 'failed', 'efaktura_error' => $response->body()]);

            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        $salesInvoice->update([
            'efaktura_status' => 'sent',
            'efaktura_sent_at' => now(),
            'efaktura_error' => null,
        ]);

        return response()->json(['status' => 'sent']);
    }

    private function authorizeSigning(Company $company, SalesInvoice $salesInvoice): void
    {
        Gate::authorize('view', $salesInvoice);
        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless($salesInvoice->status === 'confirmed', 422, 'Само потврдени фактури можат да се потпишат и испратат.');
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Праќање на е-Фактура преку фирмениот сертификат сè уште не е поддржано — регистрирај сопствен потпишувачки уред за оваа компанија.'
        );
    }
}
```

Save as `app/Http/Controllers/EfakturaSendController.php`.

- [ ] **Step 2: Register the routes**

In `routes/web.php`, add the import:
```php
use App\Http\Controllers\EfakturaSendController;
```

Add a new group right after the existing `sales-invoices.` group:
```php
Route::middleware(['auth'])->prefix('companies/{company}/sales-invoices/{salesInvoice}')->name('sales-invoices.efaktura.')->group(function () {
    Route::post('/efaktura/signing-input', [EfakturaSendController::class, 'signingInput'])->name('signing-input');
    Route::post('/efaktura/send', [EfakturaSendController::class, 'send'])->name('send');
});
```

- [ ] **Step 3: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaSendControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function makeConfirmedOwnModeInvoice(): array
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'invoice_date' => '2026-03-01', 'status' => 'confirmed',
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        return [$company, $invoice->fresh(['lines'])];
    }

    public function test_signing_input_returns_token_and_signing_input(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_send_completes_and_marks_invoice_sent(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $sendResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $sendResponse->assertOk()->assertJson(['status' => 'sent']);
        $this->assertSame('sent', $invoice->fresh()->efaktura_status);
        $this->assertNotNull($invoice->fresh()->efaktura_sent_at);
    }

    public function test_send_with_expired_token_returns_410(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => 'nonexistent-token', 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertStatus(410);
    }

    public function test_send_when_ujp_rejects_marks_invoice_failed(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid signature'], 400)]);
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $sendResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $sendResponse->assertStatus(422);
        $this->assertSame('failed', $invoice->fresh()->efaktura_status);
        $this->assertNotNull($invoice->fresh()->efaktura_error);
    }

    public function test_firm_mode_company_is_rejected_with_clear_message(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_draft_invoice_is_rejected(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'draft', 'invoice_date' => '2026-03-01',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_client_role_is_forbidden(): void
    {
        [$company, $invoice] = $this->makeConfirmedOwnModeInvoice();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
```

Save as `tests/Feature/EfakturaSendControllerTest.php`.

- [ ] **Step 4: Run the tests and confirm they fail**

Run:
```powershell
php artisan test --filter EfakturaSendControllerTest
```
Expected: FAIL — routes don't exist yet (`Route [sales-invoices.efaktura.signing-input] not defined`).

- [ ] **Step 5: Run the tests and confirm they pass**

Run:
```powershell
php artisan test --filter EfakturaSendControllerTest
```
Expected: `OK (7 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/EfakturaSendController.php routes/web.php tests/Feature/EfakturaSendControllerTest.php
git commit -m "feat: add signing-input and send routes for e-invoice sign-and-send flow"
```

---

### Task 16: Remove the unused `web-token/jwt-framework` dependency

**Files:**
- Modify: `composer.json`, `composer.lock`

**Interfaces:** none — pure cleanup, confirmed unused anywhere in `app/` (only the already-deleted throwaway spike command referenced it).

- [ ] **Step 1: Remove the package**

Run:
```powershell
composer remove web-token/jwt-framework
```
Expected: composer reports removal and updates `composer.lock` accordingly, no errors.

- [ ] **Step 2: Run the full test suite to confirm nothing else depended on it**

Run:
```powershell
php artisan test
```
Expected: same pass/fail counts as before this task (no new failures) — confirms no other file quietly used `Jose\Component\*` classes.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: remove unused web-token/jwt-framework dependency (JWS is hand-assembled)"
```

---

### Task 17: SalesInvoiceShow — "Потпиши и испрати" button and the full browser JS flow

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceShow.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php`
- Test: `tests/Feature/SalesInvoiceShowEfakturaTest.php`

**Interfaces:**
- Consumes: `sales-invoices.efaktura.signing-input`/`sales-invoices.efaktura.send` routes (Task 15), `Company::hasEfakturaAccess()` (Task 6).
- Produces: the end-user-facing sign-and-send button. `SalesInvoiceShow::render()` passes `$company` (already a public property) into the view, which the new JS reads via `@js()`.

- [ ] **Step 1: Confirm `render()` exposes what the view needs**

`SalesInvoiceShow` already has `public Company $company` and passes `$invoice` to the view; no PHP changes are needed in the component itself for this task — the button/flow is view-only, calling the Task 15 routes directly via `fetch()`. (If a later manual check finds the view needs a computed value not already available — e.g. the invoice's current `efaktura_status` — that's already on `$invoice` since Task 7 added it as a real column, no extra work needed.)

- [ ] **Step 2: Add the button and JS to the view**

In `resources/views/livewire/invoicing/sales-invoice-show.blade.php`, near the existing `@if ($invoice->status === 'confirmed')` block that renders "Означи како испратена" (around the `markSent` button), add a new section:

```blade
    @if ($invoice->status === 'confirmed' && auth()->user()->hasAnyRole(['admin', 'accountant']))
        <div class="mt-4 border-t pt-4" x-data="efakturaSend()">
            @if ($invoice->efaktura_status === 'sent')
                <x-badge status="active">Испратена до УЈП ({{ optional($invoice->efaktura_sent_at)->format('d.m.Y H:i') }})</x-badge>
            @elseif (! $company->hasEfakturaAccess() || $company->efaktura_credential_mode !== \App\Models\Company::EFAKTURA_MODE_OWN)
                <p class="text-xs text-gray-500">Регистрирај потпишувачки уред за оваа компанија (Профил на фирма) за да можеш да праќаш е-Фактура.</p>
            @else
                <button type="button" @click="run()" :disabled="busy" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
                    <span x-show="!busy">Потпиши и испрати до УЈП</span>
                    <span x-show="busy" x-text="statusText"></span>
                </button>
                @if ($invoice->efaktura_status === 'failed')
                    <p class="text-red-600 text-sm mt-2">Претходен обид не успеа: {{ Str::limit($invoice->efaktura_error, 200) }}</p>
                @endif
                <p x-show="error" x-text="error" class="text-red-600 text-sm mt-2"></p>
                <p x-show="success" class="text-green-700 text-sm mt-2">Фактурата е успешно испратена до УЈП.</p>
            @endif
        </div>

        @script
        <script>
            Alpine.data('efakturaSend', () => ({
                busy: false,
                error: '',
                success: false,
                statusText: '',
                async run() {
                    this.busy = true; this.error = ''; this.success = false;
                    try {
                        this.statusText = 'Проверувам мост...';
                        const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
                        if (!health || !health.ok) {
                            throw new Error('Локалниот потпишувач не работи. Стартувај го и обиди се повторно.');
                        }

                        this.statusText = 'Читам токен...';
                        const certRes = await fetch('http://127.0.0.1:9847/certificate');
                        if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот.');
                        const cert = await certRes.json();

                        if (cert.serialNumber !== @js($company->efaktura_token_serial_number)) {
                            throw new Error('Приклучениот токен не одговара на регистрираниот за оваа компанија.');
                        }

                        this.statusText = 'Подготвувам текст за потпишување...';
                        const signingRes = await fetch(@js(route('sales-invoices.efaktura.signing-input', [$company, $invoice])), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ certificateBase64: cert.certificateBase64 }),
                        });
                        if (!signingRes.ok) throw new Error('Серверот не можеше да го подготви текстот за потпишување.');
                        const { token, signingInput } = await signingRes.json();

                        this.statusText = 'Потпишувам (проверете го прозорецот на SafeNet)...';
                        const signRes = await fetch('http://127.0.0.1:9847/sign', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ data: signingInput }),
                        });
                        if (!signRes.ok) throw new Error('Потпишувањето не успеа — провери го PIN-от на токенот.');
                        const { signature } = await signRes.json();

                        this.statusText = 'Праќам до УЈП...';
                        const sendRes = await fetch(@js(route('sales-invoices.efaktura.send', [$company, $invoice])), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ token, signature }),
                        });
                        const sendBody = await sendRes.json();
                        if (!sendRes.ok) {
                            throw new Error(sendBody.error === 'ujp_rejected' ? `УЈП го одби барањето: ${sendBody.body}` : 'Праќањето не успеа.');
                        }

                        this.success = true;
                        setTimeout(() => window.location.reload(), 1500);
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.busy = false;
                    }
                },
            }));
        </script>
        @endscript
    @endif
```

Read the view file first to place this block correctly relative to the existing `markSent` button and confirm the exact surrounding markup (indentation, whether it's inside or after the existing `@if ($invoice->status === 'confirmed')` block) before inserting.

- [ ] **Step 3: Write the feature test**

This test can't exercise the actual JS/bridge/UJP round trip (no real browser, no real token) — it only verifies the button/status rendering logic server-side.

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceShowEfakturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_sign_and_send_button_hidden_without_registered_device(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_visible_for_admin_with_registered_device(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Потпиши и испрати до УЈП');
    }

    public function test_sign_and_send_button_hidden_for_client(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП');
    }

    public function test_already_sent_invoice_shows_sent_badge_not_button(): void
    {
        $company = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'status' => 'confirmed', 'invoice_date' => '2026-03-01',
            'efaktura_status' => 'sent', 'efaktura_sent_at' => now(),
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('Испратена до УЈП')
            ->assertDontSee('Потпиши и испрати до УЈП');
    }
}
```

Save as `tests/Feature/SalesInvoiceShowEfakturaTest.php`.

- [ ] **Step 4: Run the tests and confirm they fail, then pass**

Run:
```powershell
php artisan test --filter SalesInvoiceShowEfakturaTest
```
Expected: FAIL before Step 2, `OK (4 tests, ...)` after.

- [ ] **Step 5: Run the full existing `SalesInvoiceShowTest` suite to confirm no regression**

Run:
```powershell
php artisan test --filter SalesInvoiceShowTest
```
Expected: `OK` — unchanged.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceShow.php resources/views/livewire/invoicing/sales-invoice-show.blade.php tests/Feature/SalesInvoiceShowEfakturaTest.php
git commit -m "feat: add sign-and-send-to-UJP button and browser JS flow to the invoice screen"
```

---

### Task 18: End-to-end live verification against `efakturatest.ujp.gov.mk`

**Files:** none — this task runs the already-built system, no code changes expected unless UJP rejects the JWS format.

**Interfaces:** exercises the full chain built in Tasks 1–17.

This is the same "iterate until UJP accepts the format" step the design doc always planned for, now done against the real send flow instead of a throwaway spike command.

- [ ] **Step 1: Prerequisites — THIS STEP IS FOR THE USER**

1. Confirm `.env` has `EFAKTURA_BASE_URL=https://efakturatest.ujp.gov.mk` (already the default).
2. On the real production company (id 2, per project memory) or a fresh test company: set `efaktura_credential_mode` to `own`, fill in `efaktura_eujp_id`, save.
3. Fill in the company's structured address fields (street/number/postal/city) via the "Уреди" form (Task 8/5).
4. Start the published bridge (`public/downloads/efaktura-bridge/EfakturaBridge.Server.exe`, or `dotnet run` from source) with the USB token plugged in.
5. On the company dashboard, click "Провери токен" → "Потврди — ова е точниот уред" to register the signing device (Task 8).
6. Create a confirmed sales invoice with at least one line, real partner with a filled-in address.

- [ ] **Step 2: Run the real sign-and-send flow — THIS STEP IS FOR THE USER**

Open the invoice's show page, click "Потпиши и испрати до УЈП", enter the token PIN when SafeNet's popup appears, and observe the result.

Expected: either
- **Success** — page shows "Фактурата е успешно испратена до УЈП." and the badge afterward reads "Испратена до УЈП (...)". Paste back confirmation.
- **UJP rejects the request** — paste back the exact error message shown (which includes `sendBody.body`, i.e. UJP's raw response body) and the HTTP status. This is expected to potentially happen on the first real attempt, per every planning document for this feature going back to the original Phase 8 design doc — the JWS header shape (`alg`+`x5c` only, no `typ`) and the `X-SERIAL-NUMBER` header value (hex serial number as returned by `X509Certificate2.SerialNumber` from the bridge) are both best-guesses never confirmed against the live UJP test environment before now.

- [ ] **Step 3: If UJP rejects — iterate on `EfakturaJwsService`/`EfakturaDocumentBuilder`, not the bridge**

Common first-attempt failure modes to check, in order:
1. **Header shape** — UJP may expect a `typ` claim (e.g. `"typ":"JOSE"` or `"typ":"JWT"`) in the protected header. Add it to `EfakturaJwsService::buildSigningInput()`'s `$header` array and re-test.
2. **`X-SERIAL-NUMBER` format** — UJP may expect decimal instead of hex, or with/without leading zeros stripped, or colon-separated hex. If so, this needs a companion fix in the bridge's `CertificateInfo` (add a `SerialNumberDecimal` field alongside the existing hex `SerialNumber`) plus a matching change in `EfakturaJwsService::send()` — treat this as a small follow-up task if it happens, don't guess preemptively.
3. **Payload field mismatches** — compare the actual JSON sent (log it, or re-derive it from the cached `payload_json`) field-by-field against `primer_za_json_2.pdf`'s worked examples.

Re-run Task 18 Step 2 after each change until UJP accepts a real invoice.

- [ ] **Step 4: Once confirmed working, no commit needed for this task itself** — any code changes made during iteration in Step 3 get their own commit at that time, following the same "write test, see it fail, fix, see it pass, commit" discipline as every other task in this plan.

---

## Self-Review Notes

- **Spec coverage:** design doc §Б/§В (new Laravel routes for signing-input + send, JS sequence health→certificate→signing-input→bridge-sign→send, Phase 8a UI rework to "Регистрирај потпишувачки уред", removal of cert-storage columns) — Tasks 5–8, 14–17. The three explicitly-deferred 8b-i questions (PIN model, Origin/Host hardening, PNA) — Tasks 1–3, each ordered *before* any UI/JS work per the user's explicit instruction, with Task 3 ending in a mandatory real-browser gate before Task 8 onward. Structured address columns and `payment_type_code` (carried over as still-valid from the old JWS-send design) — Tasks 5, 7, 10. JWS-format decision (hand-rolled vs. `web-token/jwt-framework`) — resolved in Global Constraints with reasoning, executed in Tasks 11, 14, 16.
- **Explicitly out of scope, with reasoning given inline:** `firm`-mode credential resolution (Global Constraints + `EfakturaSendController::authorizeSigning`), storno/credit-note invoices (`docStorno` hardcoded to 0 — only confirmed, non-cancelled invoices reach the button), multi-currency (app has no currency column on sales invoices).
- **Placeholder scan:** every step has real code, real file paths, real commands with expected output; no "TBD"/"add validation"/"similar to Task N" left in any step.
- **Type consistency check:** `EfakturaTaxIndicator::code()/percent()` (Task 12) signatures match every call site in `EfakturaDocumentBuilder` (Task 13). `EfakturaDocumentBuilder::build(SalesInvoice): array` (Task 13) matches its only caller in `EfakturaJwsService::buildSigningInput` (Task 14). `EfakturaJwsService::buildSigningInput()`'s return shape (`['signingInput' => ..., 'payloadJson' => ...]`) matches how `EfakturaSendController::signingInput` (Task 15) destructures it. `Base64Url::encode(string)/decode(string)` (Task 11, PHP) mirrors the already-shipped `.NET` `Base64Url` from 8b-i in behavior (RFC 4648 base64url, no padding) but is a separate implementation in a separate language — intentional, not a bug. `BridgeRequest.HostHeader`/`PrivateNetworkRequested` (Tasks 2, 3) are read in `Program.cs` and consumed in `RequestRouter.Handle`, with every pre-existing `RequestRouterTests.cs` case updated to set `HostHeader` so Task 2 doesn't silently break Task 4 (8b-i)'s already-passing suite.
