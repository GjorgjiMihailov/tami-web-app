using System;
using System.Collections.Generic;
using System.Security.Cryptography.X509Certificates;
using Net.Pkcs11Interop.Common;
using Net.Pkcs11Interop.HighLevelAPI;

namespace EfakturaBridge.Core;

public sealed class Pkcs11SigningService : IPkcs11SigningService, IDisposable
{
    private readonly string _libraryPath;
    private readonly Pkcs11InteropFactories _factories = new();
    private readonly TokenSessionCache _sessions;

    /// <param name="idleMinutes">
    /// Колку минути мирување ја затвора отклучената сесија. PIN-от се внесува
    /// еднаш и важи додека се работи; по толку мирување (или со „Заклучи")
    /// следното потпишување повторно бара PIN.
    /// </param>
    public Pkcs11SigningService(string libraryPath, int idleMinutes = 120)
    {
        _libraryPath = libraryPath;
        IdleMinutes = idleMinutes;
        _sessions = new TokenSessionCache(
            () => new Pkcs11TokenSession(_libraryPath, _factories),
            TimeSpan.FromMinutes(idleMinutes),
            tickInterval: TimeSpan.FromSeconds(30));
    }

    public int IdleMinutes { get; }

    public bool IsUnlocked => _sessions.IsUnlocked;

    public void Lock() => _sessions.Lock();

    public void Dispose() => _sessions.Dispose();

    public CertificateInfo GetCertificateInfo()
    {
        using IPkcs11Library library = LoadLibrary();
        ISlot slot = Pkcs11TokenSession.GetFirstSlotWithToken(library);
        using ISession session = slot.OpenSession(SessionType.ReadOnly);
        IObjectHandle certObject = Pkcs11TokenSession.FindCertificateObject(_factories, session);
        byte[] certDer = Pkcs11TokenSession.ReadCertificateDer(session, certObject);

        using X509Certificate2 cert = new X509Certificate2(certDer);
        return new CertificateInfo(
            cert.SerialNumber,
            cert.Subject,
            cert.NotBefore,
            cert.NotAfter,
            Convert.ToBase64String(certDer));
    }

    public byte[] Sign(byte[] data) => _sessions.Sign(data);

    private IPkcs11Library LoadLibrary()
    {
        return _factories.Pkcs11LibraryFactory.LoadPkcs11Library(_factories, _libraryPath, AppType.MultiThreaded);
    }
}

/// <summary>
/// Отворена и најавена сесија со токенот. Најавата (PIN) се случува еднаш, при
/// отворањето; потпишувањата што следуваат ја користат истата сесија.
/// </summary>
internal sealed class Pkcs11TokenSession : ITokenSession
{
    private readonly Pkcs11InteropFactories _factories;
    private readonly IPkcs11Library _library;
    private readonly ISession _session;
    private bool _loggedIn;

    public Pkcs11TokenSession(string libraryPath, Pkcs11InteropFactories factories)
    {
        _factories = factories;
        _library = factories.Pkcs11LibraryFactory.LoadPkcs11Library(factories, libraryPath, AppType.MultiThreaded);

        try
        {
            ISlot slot = GetFirstSlotWithToken(_library);
            _session = slot.OpenSession(SessionType.ReadWrite);

            // SafeNet's own popup handles PIN entry regardless of what ProtectedAuthenticationPath
            // reports (confirmed against the real token in plan 8b-i) — a console-input fallback
            // here would silently block the single-threaded HTTP accept loop forever, since the
            // console prompt receives no input while SafeNet's popup has focus.
            _session.Login(CKU.CKU_USER, (string?)null);
            _loggedIn = true;
        }
        catch
        {
            _library.Dispose();
            throw;
        }
    }

    public byte[] Sign(byte[] data)
    {
        byte[]? certId = TryGetCertificateId(_factories, _session);
        IObjectHandle privateKey = FindPrivateKey(_factories, _session, certId);
        IMechanism mechanism = _factories.MechanismFactory.Create(CKM.CKM_SHA256_RSA_PKCS);
        return _session.Sign(mechanism, privateKey, data);
    }

    public void Logout()
    {
        if (!_loggedIn)
            return;

        _loggedIn = false;
        _session.Logout();
    }

    public void Dispose()
    {
        _session.Dispose();
        _library.Dispose();
    }

    internal static ISlot GetFirstSlotWithToken(IPkcs11Library library)
    {
        List<ISlot> slots = library.GetSlotList(SlotsType.WithTokenPresent);
        if (slots.Count == 0)
            throw new InvalidOperationException("Нема приклучен токен.");
        return slots[0];
    }

    internal static IObjectHandle FindCertificateObject(Pkcs11InteropFactories factories, ISession session)
    {
        List<IObjectAttribute> searchAttrs = new List<IObjectAttribute>
        {
            factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_CERTIFICATE)
        };
        List<IObjectHandle> certObjects = session.FindAllObjects(searchAttrs);
        if (certObjects.Count == 0)
            throw new InvalidOperationException("Не е најден сертификат на токенот.");
        return certObjects[0];
    }

    internal static byte[] ReadCertificateDer(ISession session, IObjectHandle certObject)
    {
        List<IObjectAttribute> values = session.GetAttributeValue(certObject, new List<CKA> { CKA.CKA_VALUE });
        return values[0].GetValueAsByteArray();
    }

    /// <summary>
    /// Reads the CKA_ID of the token's certificate object, so the matching private key
    /// can be located. Returns null if the certificate can't be found or has no CKA_ID
    /// set, in which case the caller falls back to selecting the first private key.
    /// </summary>
    private static byte[]? TryGetCertificateId(Pkcs11InteropFactories factories, ISession session)
    {
        try
        {
            IObjectHandle certObject = FindCertificateObject(factories, session);
            List<IObjectAttribute> values = session.GetAttributeValue(certObject, new List<CKA> { CKA.CKA_ID });
            byte[] id = values[0].GetValueAsByteArray();
            return id.Length > 0 ? id : null;
        }
        catch (InvalidOperationException)
        {
            return null;
        }
    }

    /// <summary>
    /// Finds the private key matching the certificate's CKA_ID, when available. Falls back
    /// to the first private key object on the token when certId is null or no key shares
    /// that CKA_ID, preserving the original behavior for tokens that don't set CKA_ID.
    /// </summary>
    private static IObjectHandle FindPrivateKey(Pkcs11InteropFactories factories, ISession session, byte[]? certId)
    {
        if (certId is not null)
        {
            List<IObjectAttribute> matchingAttrs = new List<IObjectAttribute>
            {
                factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_PRIVATE_KEY),
                factories.ObjectAttributeFactory.Create(CKA.CKA_ID, certId)
            };
            List<IObjectHandle> matchingKeys = session.FindAllObjects(matchingAttrs);
            if (matchingKeys.Count > 0)
                return matchingKeys[0];
        }

        List<IObjectAttribute> searchAttrs = new List<IObjectAttribute>
        {
            factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_PRIVATE_KEY)
        };
        List<IObjectHandle> keyObjects = session.FindAllObjects(searchAttrs);
        if (keyObjects.Count == 0)
            throw new InvalidOperationException("Не е најден приватен клуч на токенот.");
        return keyObjects[0];
    }
}
