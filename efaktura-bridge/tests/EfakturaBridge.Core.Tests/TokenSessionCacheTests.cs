using System;
using System.Collections.Generic;
using EfakturaBridge.Core;
using Xunit;

namespace EfakturaBridge.Core.Tests;

public class TokenSessionCacheTests
{
    private sealed class FakeSession : ITokenSession
    {
        public int Signs { get; private set; }
        public bool LoggedOut { get; private set; }
        public bool Disposed { get; private set; }
        public Func<int, Exception?>? FailOnSign { get; set; }

        public byte[] Sign(byte[] data)
        {
            Signs++;
            Exception? failure = FailOnSign?.Invoke(Signs);
            if (failure is not null)
                throw failure;
            return new byte[] { 1, 2, 3 };
        }

        public void Logout() => LoggedOut = true;

        public void Dispose() => Disposed = true;
    }

    private sealed class Harness
    {
        public List<FakeSession> Opened { get; } = new();
        public DateTimeOffset Now { get; set; } = new DateTimeOffset(2026, 9, 29, 9, 0, 0, TimeSpan.Zero);
        public Func<FakeSession>? Factory { get; set; }

        public TokenSessionCache Build(int idleMinutes = 30) => new TokenSessionCache(
            () =>
            {
                FakeSession session = Factory?.Invoke() ?? new FakeSession();
                Opened.Add(session);
                return session;
            },
            TimeSpan.FromMinutes(idleMinutes),
            () => Now);
    }

    private static readonly byte[] Data = { 7 };

    [Fact]
    public void ThePinIsAskedOnceThenTheSessionIsReused()
    {
        Harness h = new();
        TokenSessionCache cache = h.Build();

        cache.Sign(Data);
        cache.Sign(Data);
        cache.Sign(Data);

        Assert.Single(h.Opened);            // отворена (= PIN побаран) само еднаш
        Assert.Equal(3, h.Opened[0].Signs);
        Assert.True(cache.IsUnlocked);
    }

    [Fact]
    public void ANewSessionIsOpenedAfterTheIdleTimeout()
    {
        Harness h = new();
        TokenSessionCache cache = h.Build(idleMinutes: 30);

        cache.Sign(Data);
        h.Now = h.Now.AddMinutes(31);
        Assert.False(cache.IsUnlocked);      // мирувањето ја заклучи
        Assert.True(h.Opened[0].LoggedOut);
        Assert.True(h.Opened[0].Disposed);

        cache.Sign(Data);

        Assert.Equal(2, h.Opened.Count);     // повторно PIN
    }

    [Fact]
    public void UsingTheSessionKeepsItAliveSoTheTimeoutCountsFromTheLastUse()
    {
        Harness h = new();
        TokenSessionCache cache = h.Build(idleMinutes: 30);

        cache.Sign(Data);
        h.Now = h.Now.AddMinutes(25);
        cache.Sign(Data);
        h.Now = h.Now.AddMinutes(25);        // 50 мин од првото, но само 25 од последното
        cache.Sign(Data);

        Assert.Single(h.Opened);
    }

    [Fact]
    public void LockLogsOutAndTheNextSignAsksForThePinAgain()
    {
        Harness h = new();
        TokenSessionCache cache = h.Build();

        cache.Sign(Data);
        cache.Lock();

        Assert.False(cache.IsUnlocked);
        Assert.True(h.Opened[0].LoggedOut);

        cache.Sign(Data);
        Assert.Equal(2, h.Opened.Count);
    }

    [Fact]
    public void LockingWhenNothingIsOpenIsHarmless()
    {
        Harness h = new();
        TokenSessionCache cache = h.Build();

        cache.Lock();
        cache.Lock();

        Assert.False(cache.IsUnlocked);
        Assert.Empty(h.Opened);
    }

    [Fact]
    public void TickClosesAnIdleSessionWithoutWaitingForTheNextRequest()
    {
        Harness h = new();
        TokenSessionCache cache = h.Build(idleMinutes: 10);

        cache.Sign(Data);
        h.Now = h.Now.AddMinutes(11);
        cache.Tick();

        Assert.True(h.Opened[0].LoggedOut);
    }

    [Fact]
    public void AStaleSessionIsReplacedOnceAndTheSignatureStillSucceeds()
    {
        Harness h = new();
        int created = 0;
        h.Factory = () =>
        {
            created++;
            // Првата сесија успева еднаш, па „умира“ (токенот е извлечен).
            return new FakeSession { FailOnSign = call => created == 1 && call >= 2 ? new InvalidOperationException("сесијата не важи") : null };
        };
        TokenSessionCache cache = h.Build();

        cache.Sign(Data);                    // сесија 1, успех
        byte[] signature = cache.Sign(Data); // сесија 1 падна → сесија 2, успех

        Assert.Equal(new byte[] { 1, 2, 3 }, signature);
        Assert.Equal(2, h.Opened.Count);
        Assert.True(h.Opened[0].Disposed);
        Assert.True(cache.IsUnlocked);
    }

    [Fact]
    public void IfTheReplacementSessionFailsTooTheErrorReachesTheCallerAndNothingIsKept()
    {
        Harness h = new();
        int created = 0;
        h.Factory = () =>
        {
            created++;
            return new FakeSession { FailOnSign = call => created >= 2 || call >= 2 ? new InvalidOperationException("токенот не одговара") : null };
        };
        TokenSessionCache cache = h.Build();

        cache.Sign(Data);
        InvalidOperationException error = Assert.Throws<InvalidOperationException>(() => cache.Sign(Data));

        Assert.Equal("токенот не одговара", error.Message);
        Assert.False(cache.IsUnlocked);
    }

    [Fact]
    public void AFirstSignatureThatFailsIsNotCachedSoAWrongPinIsNeverRemembered()
    {
        Harness h = new();
        h.Factory = () => new FakeSession { FailOnSign = _ => new InvalidOperationException("Погрешен PIN.") };
        TokenSessionCache cache = h.Build();

        Assert.Throws<InvalidOperationException>(() => cache.Sign(Data));

        Assert.False(cache.IsUnlocked);
        Assert.Single(h.Opened);            // без повторување: не се проба втор пат врз лошата сесија
        Assert.True(h.Opened[0].Disposed);
    }

    [Fact]
    public void OpeningTheSessionFailingDoesNotLeaveAHalfOpenState()
    {
        int attempts = 0;
        TokenSessionCache cache = new TokenSessionCache(
            () =>
            {
                attempts++;
                if (attempts == 1)
                    throw new InvalidOperationException("Нема приклучен токен.");
                return new FakeSession();
            },
            TimeSpan.FromMinutes(30));

        Assert.Throws<InvalidOperationException>(() => cache.Sign(Data));
        Assert.False(cache.IsUnlocked);

        cache.Sign(Data);                   // вториот обид (токенот приклучен) работи
        Assert.True(cache.IsUnlocked);
    }
}
