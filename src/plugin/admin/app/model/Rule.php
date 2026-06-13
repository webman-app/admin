<?php
declare(strict_types=1);

namespace plugin\admin\app\model;

/**
 * @property integer $id 主键(主键)
 * @property string $title 标题
 * @property string $icon 图标
 * @property string $key 标识
 * @property integer $pid 上级菜单
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string $href url
 * @property integer $type 类型
 * @property integer $open_type 菜单打开方式
 * @property integer $weight 排序
 */
class Rule extends Base
{
    public const TYPE_DIR = 0;
    public const TYPE_MENU = 1;
    public const TYPE_PERMISSION = 2;

    public const OPEN_TYPE_IFRAME = '_iframe';
    public const OPEN_TYPE_BLANK = '_blank';
    public const OPEN_TYPE_LAYER = '_layer';
    public const OPEN_TYPE_COMPONENT = '_component';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_rules';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';
}
