namespace EfakturaBridge.Core;

public interface IPkcs11SigningService
{
    CertificateInfo GetCertificateInfo();
    byte[] Sign(byte[] data);
}
