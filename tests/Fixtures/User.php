<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'sso_id'];
}
