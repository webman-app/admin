<?php
declare(strict_types=1);

namespace Jenssegers\Blade;

use Illuminate\Container\Container;

class Application extends Container
{
    public function getNamespace(): string
    {
        return 'app\\';
    }
}
