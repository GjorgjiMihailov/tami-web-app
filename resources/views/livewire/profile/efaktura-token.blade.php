<div x-data="personalSigningDevice()">
    @php
        $expires = $user->efaktura_token_not_after;
        $expired = $expires && $expires->isPast();
        $expiresSoon = $expires && ! $expired && $expires->lte(now()->addDays(30));
    @endphp

    <h3 class="text-lg font-medium text-gray-900">е-Фактура — мој токен</h3>
    <p class="mt-1 text-sm text-gray-600">
        Токенот и е-УЈП ID-то се лични. Со нив потпишуваш е-Фактури за фирмите за кои си овластен во е-УЈП.
        Овластувањето за секоја фирма го даваш во е-УЈП, не тука.
    </p>

    <ul class="mt-3 text-sm space-y-0.5">
        <li class="{{ filled($user->efaktura_eujp_id) ? 'text-green-700' : 'text-amber-700' }}">
            {{ filled($user->efaktura_eujp_id) ? '✓' : '✗' }} Мој е-УЈП ID
        </li>
        <li class="{{ $user->efaktura_token_serial_number && ! $expired ? 'text-green-700' : 'text-amber-700' }}">
            {{ $user->efaktura_token_serial_number && ! $expired ? '✓' : '✗' }} Регистриран токен (сертификат)
        </li>
    </ul>

    <form wire:submit="saveEujpId" class="mt-4 flex flex-wrap items-end gap-3">
        <div>
            <x-input-label for="eujpId" value="Мој е-УЈП ID (X-EUJP-ID)" />
            <x-text-input id="eujpId" wire:model="eujpId" class="w-72" />
            @error('eujpId') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
        </div>
        <x-secondary-button type="submit">Зачувај</x-secondary-button>
        @if ($saved)
            <span class="text-sm text-green-700">Зачувано.</span>
        @endif
    </form>

    @if ($user->efaktura_token_serial_number)
        <p class="mt-4 text-sm text-gray-600">
            Регистриран: <span class="font-medium">{{ $user->efaktura_token_subject_name }}</span>
            (сериски бр. {{ $user->efaktura_token_serial_number }})
        </p>
        <p class="text-xs text-gray-500">Важи до {{ optional($expires)->format('d.m.Y') }}</p>
    @else
        <p class="mt-4 text-sm text-gray-500">Нема регистриран токен.</p>
    @endif

    @if ($expired)
        <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">Сертификатот е истечен ({{ $expires->format('d.m.Y') }}). Приклучи го новиот токен и кликни „Ажурирај сертификат“.</p>
    @elseif ($expiresSoon)
        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Сертификатот истекува на {{ $expires->format('d.m.Y') }}. Кога ќе добиеш нов, кликни „Ажурирај сертификат“.</p>
    @endif

    <div class="mt-3 flex flex-wrap items-center gap-3">
        <button type="button" @click="check()" :disabled="busy" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-full font-semibold text-sm text-gray-700 shadow-sm hover:bg-paper disabled:opacity-50">
            <span x-show="!busy">{{ $user->efaktura_token_serial_number ? 'Ажурирај сертификат' : 'Регистрирај токен' }}</span>
            <span x-show="busy">Читам...</span>
        </button>
        @if ($user->efaktura_token_serial_number)
            <button type="button" wire:click="removeToken" wire:confirm="Да го отстранам регистрираниот токен?" class="text-sm text-red-600 hover:underline">Отстрани го токенот</button>
        @endif
        <a href="{{ asset('downloads/efaktura-bridge/EfakturaBridge.Server.exe') }}" class="text-brand hover:underline text-sm">Преземи локален потпишувач</a>
    </div>

    <div x-show="detected" x-cloak class="mt-3 border rounded-lg p-3 bg-gray-50">
        <p class="text-sm">Пронајден: <span x-text="subjectName" class="font-medium"></span></p>
        <p class="text-xs text-gray-500">Сериски бр. <span x-text="serialNumber"></span>, важи до <span x-text="notAfter"></span></p>
        <button type="button" @click="confirmRegister()" class="mt-2 rounded-full bg-brand text-white px-4 py-1.5 text-sm">
            {{ $user->efaktura_token_serial_number ? 'Потврди — замени го регистрираниот токен' : 'Потврди — ова е мојот токен' }}
        </button>
    </div>

    <p x-show="error" x-text="error" class="text-red-600 text-sm mt-2"></p>
    @error('signingDevice') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror

    @script
    <script>
        Alpine.data('personalSigningDevice', () => ({
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
</div>
