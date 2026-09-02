<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\Form743Status;
use Illuminate\Database\Eloquent\Factories\Factory;

class Form743Factory extends Factory
{
    /**
     * Стандардно прави необработен образец — токму она што клиентот го качува,
     * без ниту едно поле од самиот образец. Тест што сака внесена пријава го
     * поставува тоа изречно преку filed().
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory()->state(['type' => CompanyType::INDIVIDUAL]),
            'status' => Form743Status::PENDING,
            'payer' => null,
            'amount' => null,
            'currency' => null,
            'payment_date' => null,
            'basis' => null,
            'uploaded_by' => User::factory(),
            'filed_by' => null,
            'filed_at' => null,
        ];
    }

    public function filed(): static
    {
        return $this->state(fn () => [
            'status' => Form743Status::FILED,
            'payer' => 'Acme Ltd, London',
            'amount' => '61500.00',
            'currency' => 'EUR',
            'payment_date' => '2026-03-10',
            'basis' => 'Авторски хонорар',
            'filed_by' => User::factory(),
            'filed_at' => now(),
        ]);
    }
}
