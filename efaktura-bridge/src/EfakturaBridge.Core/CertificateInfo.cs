using System;

namespace EfakturaBridge.Core;

public sealed record CertificateInfo(
    string SerialNumber,
    string SubjectName,
    DateTime NotBefore,
    DateTime NotAfter,
    string CertificateBase64);
