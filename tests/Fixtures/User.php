<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $casts = ['may_use_files' => 'boolean'];
}
