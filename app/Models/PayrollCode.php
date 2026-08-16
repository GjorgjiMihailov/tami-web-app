<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PayrollCode extends Model
{
    public const TYPES = ['opstina', 'vid_staz', 'sifra_dviz', 'osloboduvanje', 'rab_cas', 'vid_nadomestoci'];

    protected $fillable = ['type', 'code', 'name'];

    /** @return Collection<int, self> */
    public static function ofType(string $type): Collection
    {
        return static::where('type', $type)->orderBy('code')->get();
    }
}
