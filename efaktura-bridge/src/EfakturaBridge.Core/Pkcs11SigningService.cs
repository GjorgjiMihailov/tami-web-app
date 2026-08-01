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
        IObjectHandle certObject = FindCertificateObject(session);
        byte[] certDer = ReadCertificateDer(session, certObject);

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

        // SafeNet's own popup handles PIN entry regardless of what ProtectedAuthenticationPath
        // reports (confirmed against the real token in plan 8b-i) — a console-input fallback
        // here would silently block the single-threaded HTTP accept loop forever, since the
        // console prompt receives no input while SafeNet's popup has focus.
        session.Login(CKU.CKU_USER, (string?)null);

        try
        {
            byte[]? certId = TryGetCertificateId(session);
            IObjectHandle privateKey = FindPrivateKey(session, certId);
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

    private IObjectHandle FindCertificateObject(ISession session)
    {
        List<IObjectAttribute> searchAttrs = new List<IObjectAttribute>
        {
            _factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_CERTIFICATE)
        };
        List<IObjectHandle> certObjects = session.FindAllObjects(searchAttrs);
        if (certObjects.Count == 0)
            throw new InvalidOperationException("Не е најден сертификат на токенот.");
        return certObjects[0];
    }

    private byte[] ReadCertificateDer(ISession session, IObjectHandle certObject)
    {
        List<IObjectAttribute> values = session.GetAttributeValue(certObject, new List<CKA> { CKA.CKA_VALUE });
        return values[0].GetValueAsByteArray();
    }

    /// <summary>
    /// Reads the CKA_ID of the token's certificate object, so the matching private key
    /// can be located. Returns null if the certificate can't be found or has no CKA_ID
    /// set, in which case the caller falls back to selecting the first private key.
    /// </summary>
    private byte[]? TryGetCertificateId(ISession session)
    {
        try
        {
            IObjectHandle certObject = FindCertificateObject(session);
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
    private IObjectHandle FindPrivateKey(ISession session, byte[]? certId)
    {
        if (certId is not null)
        {
            List<IObjectAttribute> matchingAttrs = new List<IObjectAttribute>
            {
                _factories.ObjectAttributeFactory.Create(CKA.CKA_CLASS, CKO.CKO_PRIVATE_KEY),
                _factories.ObjectAttributeFactory.Create(CKA.CKA_ID, certId)
            };
            List<IObjectHandle> matchingKeys = session.FindAllObjects(matchingAttrs);
            if (matchingKeys.Count > 0)
                return matchingKeys[0];
        }

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
