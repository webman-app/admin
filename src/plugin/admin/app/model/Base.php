<?php
declare(strict_types=1);

namespace plugin\admin\app\model;

use DateTimeInterface;
use support\Model;


class Base extends Model
{
    /**
     * @var string
     */
    protected $connection = 'plugin.admin.mysql';

    /**
     * 格式化日期
     *
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
