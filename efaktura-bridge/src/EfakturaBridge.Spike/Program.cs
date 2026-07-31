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
Console.WriteLine($"ProtectedAuthenticationPath: {tokenInfo.TokenFlags.ProtectedAuthenticationPath}");

using ISession session = slot.OpenSession(SessionType.ReadWrite);

if (tokenInfo.TokenFlags.ProtectedAuthenticationPath)
{
    Console.WriteLine("Токенот поддржува заштитен PIN-влез — очекувајте сопствен прозорец од SafeNet.");
    session.Login(CKU.CKU_USER, (string?)null);
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
