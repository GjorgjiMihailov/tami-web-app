using System;

namespace EfakturaBridge.Core;

/// <summary>
/// Една отворена и најавена сесија со токенот: PIN-от е внесен еднаш, а
/// потпишувањата што следуваат не бараат нов.
/// </summary>
public interface ITokenSession : IDisposable
{
    byte[] Sign(byte[] data);

    void Logout();
}

/// <summary>
/// Ја чува најавената сесија со токенот додека се користи, за PIN-от да се
/// бара еднаш по сесија, а не на секое потпишување.
///
/// Сесијата се затвора (одјава) кога: ја заклучиш рачно (<see cref="Lock"/>),
/// не е користена подолго од дозволеното време на мирување, или потпишувањето
/// на постоечка сесија падне (на пример, токенот е извлечен) — тогаш се отвора
/// нова, што повторно бара PIN. Сесија што не успеала при првото потпишување
/// (погрешен PIN) никогаш не се чува.
/// </summary>
public sealed class TokenSessionCache : IDisposable
{
    private readonly Func<ITokenSession> _open;
    private readonly TimeSpan _idleTimeout;
    private readonly Func<DateTimeOffset> _now;
    private readonly object _gate = new();
    private readonly System.Threading.Timer? _timer;

    private ITokenSession? _session;
    private DateTimeOffset _lastUsed;

    public TokenSessionCache(Func<ITokenSession> open, TimeSpan idleTimeout, Func<DateTimeOffset>? now = null, TimeSpan? tickInterval = null)
    {
        _open = open;
        _idleTimeout = idleTimeout;
        _now = now ?? (() => DateTimeOffset.UtcNow);

        // Без тајмер сесијата би останала најавена и по истекот на мирувањето
        // сè додека не дојде следното барање.
        if (tickInterval is { } interval)
            _timer = new System.Threading.Timer(_ => Tick(), null, interval, interval);
    }

    public TimeSpan IdleTimeout => _idleTimeout;

    public bool IsUnlocked
    {
        get
        {
            lock (_gate)
            {
                ExpireIfIdle();
                return _session is not null;
            }
        }
    }

    public byte[] Sign(byte[] data)
    {
        lock (_gate)
        {
            ExpireIfIdle();

            bool reused = _session is not null;
            _session ??= _open();

            try
            {
                byte[] signature = _session.Sign(data);
                _lastUsed = _now();
                return signature;
            }
            catch when (reused)
            {
                // Постоечката сесија повеќе не важи (токен извлечен, драјверот ја затворил).
                // Се почнува одново — ако и тоа падне, грешката оди до корисникот.
                Discard();
            }
            catch
            {
                Discard();
                throw;
            }

            _session = _open();

            try
            {
                byte[] signature = _session.Sign(data);
                _lastUsed = _now();
                return signature;
            }
            catch
            {
                Discard();
                throw;
            }
        }
    }

    /// <summary>Рачно заклучување: одјава од токенот, следното потпишување повторно бара PIN.</summary>
    public void Lock()
    {
        lock (_gate)
            Discard();
    }

    /// <summary>Го повикува тајмерот: ја затвора сесијата што предолго мирува.</summary>
    public void Tick()
    {
        lock (_gate)
            ExpireIfIdle();
    }

    public void Dispose()
    {
        _timer?.Dispose();
        Lock();
    }

    private void ExpireIfIdle()
    {
        if (_session is not null && _now() - _lastUsed > _idleTimeout)
            Discard();
    }

    private void Discard()
    {
        ITokenSession? session = _session;
        _session = null;

        if (session is null)
            return;

        try { session.Logout(); } catch { /* токенот можеби е извлечен — нема што да се одјави */ }
        try { session.Dispose(); } catch { }
    }
}
