<?php

namespace App\Controller;

use App\Core\Dispatcher\EventDispatcher;

class BaseController
{
    public function __construct(private EventDispatcher $dispatcher)
    {}
}