<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use support\Response;
use Throwable;

/**
 * 开发辅助相关
 */
class DevController
{
    /**
     * 表单构建
     * @return Response
     * @throws Throwable
     */
    public function formBuild(): Response
    {
        return view('dev/form-build');
    }

}
