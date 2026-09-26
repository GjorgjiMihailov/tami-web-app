namespace EfakturaBridge.Core;

public interface IPkcs11SigningService
{
    CertificateInfo GetCertificateInfo();

    byte[] Sign(byte[] data);

    /// <summary>Дали токенот е отклучен (PIN-от е внесен во оваа сесија и не бара нов).</summary>
    bool IsUnlocked { get; }

    /// <summary>Колку минути мирување ја затвора отклучената сесија.</summary>
    int IdleMinutes { get; }

    /// <summary>Ја затвора отклучената сесија — следното потпишување повторно бара PIN.</summary>
    void Lock();
}
