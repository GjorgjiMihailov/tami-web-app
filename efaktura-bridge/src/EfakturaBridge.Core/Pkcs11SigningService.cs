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
