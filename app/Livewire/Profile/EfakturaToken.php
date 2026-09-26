<?php

namespace App\Livewire\Profile;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Личниот токен за потпишување е-Фактури. Токенот и е-УЈП ID-то се на
 * ЧОВЕКОТ: тој се овластува во е-УЈП за фирмите за кои работи, а барањето
 * до УЈП го носи неговиот ID и токен и ЕДБ на фирмата за која се праќа.
 */
class EfakturaToken extends Component
{
    public string $eujpId = '';

    public bool $saved = false;

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);

        $this->eujpId = (string) auth()->user()->efaktura_eujp_id;
    }

    /** Физичко лице (freelancer_client) нема е-Фактура. */
    private function allowed(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['admin', 'accountant', 'internal_client']);
    }

    public function saveEujpId(): void
    {
        abort_unless($this->allowed(), 403);

        $this->validate(['eujpId' => ['nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]*$/']]);

        auth()->user()->forceFill(['efaktura_eujp_id' => trim($this->eujpId) !== '' ? trim($this->eujpId) : null])->save();
        $this->saved = true;
    }

    /** Го повикува страницата откако локалниот потпишувач ќе го прочита токенот. */
    public function registerSigningDevice(string $serialNumber, string $subjectName, string $notBefore, string $notAfter): void
    {
        abort_unless($this->allowed(), 403);

        if (blank($serialNumber)) {
            $this->addError('signingDevice', 'Не е добиен сериски број од токенот.');

            return;
        }

        // Сериски број подолг од 100 знаци или со контролен знак не е валиден: првиот не влегува
        // во колоната, вториот го одбива HTTP-клиентот во заглавието X-SERIAL-NUMBER.
        if (mb_strlen($serialNumber) > 100 || preg_match('/[\x00-\x1F\x7F]/', $serialNumber)) {
            $this->addError('signingDevice', 'Сериски број од токенот не е валиден.');

            return;
        }

        try {
            $notBeforeParsed = Carbon::parse($notBefore);
            $notAfterParsed = Carbon::parse($notAfter);
        } catch (\Exception) {
            $this->addError('signingDevice', 'Датумите од сертификатот не можат да се прочитаат.');

            return;
        }

        auth()->user()->forceFill([
            'efaktura_token_serial_number' => $serialNumber,
            'efaktura_token_subject_name' => Str::limit($subjectName, 250, ''),
            'efaktura_token_not_before' => $notBeforeParsed,
            'efaktura_token_not_after' => $notAfterParsed,
            'efaktura_token_registered_at' => now(),
        ])->save();

        $this->resetErrorBag('signingDevice');
    }

    public function removeToken(): void
    {
        abort_unless($this->allowed(), 403);

        auth()->user()->forceFill([
            'efaktura_token_serial_number' => null,
            'efaktura_token_subject_name' => null,
            'efaktura_token_not_before' => null,
            'efaktura_token_not_after' => null,
            'efaktura_token_registered_at' => null,
        ])->save();
    }

    public function render()
    {
        return view('livewire.profile.efaktura-token', ['user' => auth()->user()->fresh()]);
    }
}
