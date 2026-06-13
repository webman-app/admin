<?php
declare(strict_types=1);

namespace plugin\admin\app\controller;

use Doctrine\Inflector\InflectorFactory;
use Illuminate\Database\Schema\Blueprint;
use plugin\admin\app\common\Layui;
use plugin\admin\app\common\Util;
use plugin\admin\app\model\Role;
use plugin\admin\app\model\Rule;
use plugin\admin\app\model\Option;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

class TableController extends Base
{
    /**
     * 不需要鉴权的方法
     * @var string[]
     */
    protected $noNeedAuth = ['types'];

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return view('table/index');
    }

    /**
     * 查看表
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function view(Request $request): Response
    {
        $table = $this->normalizeTableName($request->get('table'));
        $form = Layui::buildForm($table, 'search');
        $table_info = Util::getSchema($table, 'table');
        $primary_key = $table_info['primary_key'][0] ?? null;
        return view('table/view', [
            'form' => $form,
            'table' => $table,
            'primary_key' => $primary_key,
        ]);
    }

    /**
     * 查询表
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function show(Request $request): Response
    {
        $table_name = $request->get('table_name', '');
        if (!is_string($table_name)) {
            $table_name = '';
        }
        $table_name = $table_name === '' ? '' : Util::filterAlphaNum($table_name);
        $limit = (int)$request->get('limit', 10);
        $page = (int)$request->get('page', 1);
        $offset = ($page - 1) * $limit;
        $database = config('database.connections')['plugin.admin.mysql']['database'];
        $field = $request->get('field', 'TABLE_NAME');
        $field = is_string($field) && $field !== '' ? Util::filterAlphaNum($field) : 'TABLE_NAME';
        $order = $request->get('order', 'asc');
        $allow_column = ['TABLE_NAME', 'TABLE_COMMENT', 'ENGINE', 'TABLE_ROWS', 'CREATE_TIME', 'UPDATE_TIME', 'TABLE_COLLATION'];
        if (!in_array($field, $allow_column)) {
            $field = 'TABLE_NAME';
        }
        $order = $order === 'asc' ? 'asc' : 'desc';
        $total = Util::db()->select("SELECT count(*)total FROM  information_schema.`TABLES` WHERE  TABLE_SCHEMA='$database' AND TABLE_NAME like '%{$table_name}%'")[0]->total ?? 0;
        $tables = Util::db()->select("SELECT TABLE_NAME,TABLE_COMMENT,ENGINE,TABLE_ROWS,CREATE_TIME,UPDATE_TIME,TABLE_COLLATION FROM  information_schema.`TABLES` WHERE  TABLE_SCHEMA='$database' AND TABLE_NAME like '%{$table_name}%' order by $field $order limit $offset,$limit");

        if ($tables) {
            $table_names = array_column($tables, 'TABLE_NAME');
            $table_rows_count = [];
            foreach ($table_names as $table_name) {
                $table_rows_count[$table_name] = Util::db()->table($table_name)->count();
            }
            foreach ($tables as $table) {
                $table->TABLE_ROWS = $table_rows_count[$table->TABLE_NAME] ?? $table->TABLE_ROWS;
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'count' => $total, 'data' => $tables]);
    }

    /**
     * 创建表
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function create(Request $request): Response
    {
        if ($request->method() === 'GET') {
            return view('table/create');
        }
        $data = $request->post();
        [$table_name, $table_comment, $columns, $forms, $keys] = $this->normalizeStructureInput($data);
        $table_comment = Util::pdoQuote($table_comment);

        $primary_key_count = 0;
        foreach ($columns as $index => $item) {
            $columns[$index]['field'] = trim($item['field']);
            if (!$item['field']) {
                unset($columns[$index]);
                continue;
            }
            $columns[$index]['primary_key'] = !empty($item['primary_key']);
            if ($columns[$index]['primary_key']) {
                $primary_key_count++;
            }
            $columns[$index]['auto_increment'] = !empty($item['auto_increment']);
            $columns[$index]['nullable'] = !empty($item['nullable']);
            if ($item['default'] === '') {
                $columns[$index]['default'] = null;
            } else if ($item['default'] === "''") {
                $columns[$index]['default'] = '';
            }
        }

        if ($primary_key_count > 1) {
            throw new BusinessException('不支持复合主键');
        }

        foreach ($forms as $index => $item) {
            if (!$item['field']) {
                unset($forms[$index]);
                continue;
            }
            $forms[$index]['form_show'] = !empty($item['form_show']);
            $forms[$index]['list_show'] = !empty($item['list_show']);
            $forms[$index]['enable_sort'] = !empty($item['enable_sort']);
            $forms[$index]['searchable'] = !empty($item['searchable']);
        }

        foreach ($keys as $index => $item) {
            if (!$item['name'] || !$item['columns']) {
                unset($keys[$index]);
            }
        }

        Util::schema()->create($table_name, function (Blueprint $table) use ($columns) {
            $type_method_map = Util::methodControlMap();
            foreach ($columns as $column) {
                if (!isset($column['type'])) {
                    throw new BusinessException("请为{$column['field']}选择类型");
                }
                if (!isset($type_method_map[$column['type']])) {
                    throw new BusinessException("不支持的类型{$column['type']}");
                }
                $this->createColumn($column, $table);
            }
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
            $table->engine = 'InnoDB';
        });

        Util::db()->statement("ALTER TABLE `$table_name` COMMENT $table_comment");

        // 索引
        Util::schema()->table($table_name, function (Blueprint $table) use ($keys) {
            foreach ($keys as $key) {
                $name = $key['name'];
                $columns = is_array($key['columns']) ? $key['columns'] : explode(',', $key['columns']);
                $type = $key['type'];
                if ($type == 'unique') {
                    $table->unique($columns, $name);
                    continue;
                }
                $table->index($columns, $name);
            }
        });
        $form_schema_map = [];
        foreach ($forms as $item) {
            $form_schema_map[$item['field']] = $item;
        }
        $form_schema_map = json_encode($form_schema_map, JSON_UNESCAPED_UNICODE);
        $this->updateSchemaOption($table_name, $form_schema_map);
        return $this->json(0);
    }

    /**
     * 修改表
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function modify(Request $request): Response
    {
        if ($request->method() === 'GET') {
            return view('table/modify', ['table' => $request->get('table')]);
        }
        $data = $request->post();
        $old_table_name = $this->normalizeTableName($data['old_table'] ?? '');
        [$table_name, $table_comment, $columns, $forms, $keys] = $this->normalizeStructureInput($data);
        $primary_key = null;
        $auto_increment_column = null;
        $schema = Util::getSchema($old_table_name);
        $old_columns = $schema['columns'];
        $old_primary_key = $schema['table']['primary_key'][0] ?? null;

        $primary_key_count = $auto_increment_count = 0;
        foreach ($columns as $index => $item) {
            $columns[$index]['field'] = trim($item['field']);
            if (!$item['field']) {
                unset($columns[$index]);
                continue;
            }
            $field = $item['field'];
            $columns[$index]['auto_increment'] = !empty($item['auto_increment']);
            $columns[$index]['nullable'] = !empty($item['nullable']);
            $columns[$index]['primary_key'] = !empty($item['primary_key']);
            if ($columns[$index]['primary_key']) {
                $primary_key = $item['field'];
                $columns[$index]['nullable'] = false;
                $primary_key_count++;
            }
            if ($item['default'] === '') {
                $columns[$index]['default'] = null;
            } else if ($item['default'] === "''") {
                $columns[$index]['default'] = '';
            }
            if ($columns[$index]['auto_increment']) {
                $auto_increment_count++;
                if (!isset($old_columns[$field]) || !$old_columns[$field]['auto_increment']) {
                    $auto_increment_column = $columns[$index];
                    unset($auto_increment_column['old_field']);
                    $columns[$index]['auto_increment'] = false;
                }
            }
        }

        if ($primary_key_count > 1) {
            throw new BusinessException('不支持复合主键');
        }

        if ($auto_increment_count > 1) {
            throw new BusinessException('一个表只能有一个自增字段，并且必须为key');
        }

        foreach ($forms as $index => $item) {
            if (!$item['field']) {
                unset($forms[$index]);
                continue;
            }
            $forms[$index]['form_show'] = !empty($item['form_show']);
            $forms[$index]['list_show'] = !empty($item['list_show']);
            $forms[$index]['enable_sort'] = !empty($item['enable_sort']);
            $forms[$index]['searchable'] = !empty($item['searchable']);
        }

        foreach ($keys as $index => $item) {
            if (!$item['name'] || !$item['columns']) {
                unset($keys[$index]);
            }
        }

        // 改表名
        if ($table_name != $old_table_name) {
            Util::schema()->rename($old_table_name, $table_name);
        }

        $type_method_map = Util::methodControlMap();

        foreach ($columns as $column) {
            if (!isset($type_method_map[$column['type']])) {
                throw new BusinessException("不支持的类型{$column['type']}");
            }
            $field = $column['old_field'] ?? $column['field'];
            $old_column = $old_columns[$field] ?? [];
            // 类型更改
            foreach ($old_column as $key => $value) {
                if (key_exists($key, $column) && ($column[$key] != $value || ($key === 'default' && $column[$key] !== $value))) {
                    $this->modifyColumn($column, $table_name);
                    break;
                }
            }
        }

        $table = Util::getSchema($table_name, 'table');
        if ($table_comment !== $table['comment']) {
            $table_comment = Util::pdoQuote($table_comment);
            Util::db()->statement("ALTER TABLE `$table_name` COMMENT $table_comment");
        }

        $old_columns = Util::getSchema($table_name, 'columns');
        Util::schema()->table($table_name, function (Blueprint $table) use ($columns, $old_columns, $keys, $table_name) {
            foreach ($columns as $column) {
                $field = $column['field'];
                // 新字段
                if (!isset($old_columns[$field])) {
                    $this->createColumn($column, $table);
                }
            }
            // 更新索引名字
            foreach ($keys as $key) {
                if (!empty($key['old_name']) && $key['old_name'] !== $key['name']) {
                    $table->renameIndex($key['old_name'], $key['name']);
                }
            }
        });

        // 找到删除的字段
        $old_columns = Util::getSchema($table_name, 'columns');
        $exists_column_names = array_column($columns, 'field', 'field');
        $old_columns_names = array_column($old_columns, 'field');
        $drop_column_names = array_diff($old_columns_names, $exists_column_names);
        $drop_column_names = Util::filterAlphaNum($drop_column_names);
        foreach ($drop_column_names as $drop_column_name) {
            Util::db()->statement("ALTER TABLE `$table_name` DROP COLUMN `$drop_column_name`");
        }

        $old_keys = Util::getSchema($table_name, 'keys');
        Util::schema()->table($table_name, function (Blueprint $table) use ($keys, $old_keys, $table_name) {
            foreach ($keys as $key) {
                $key_name = $key['name'];
                $old_key = $old_keys[$key_name] ?? [];
                // 如果索引有变动，则删除索引，重新建立索引
                if ($old_key && ($key['type'] != $old_key['type'] || $key['columns'] != implode(',', $old_key['columns']))) {
                    $old_key = [];
                    unset($old_keys[$key_name]);
                    $table->dropIndex($key_name);
                }
                // 重新建立索引
                if (!$old_key) {
                    $name = $key['name'];
                    $columns = is_array($key['columns']) ? $key['columns'] : explode(',', $key['columns']);
                    $type = $key['type'];
                    if ($type == 'unique') {
                        $table->unique($columns, $name);
                        continue;
                    }
                    $table->index($columns, $name);
                }
            }

            // 找到删除的索引
            $exists_key_names = array_column($keys, 'name', 'name');
            $old_keys_names = array_column($old_keys, 'name');
            $drop_keys_names = array_diff($old_keys_names, $exists_key_names);
            foreach ($drop_keys_names as $name) {
                $table->dropIndex($name);
            }
        });

        // 变更主键
        if ($old_primary_key != $primary_key) {
            if ($old_primary_key) {
                Util::db()->statement("ALTER TABLE `$table_name` DROP PRIMARY KEY");
            }
            if ($primary_key) {
                $primary_key = Util::filterAlphaNum($primary_key);
                Util::db()->statement("ALTER TABLE `$table_name` ADD PRIMARY KEY(`$primary_key`)");
            }
        }

        // 一个表只能有一个 auto_increment 字段，并且是key，所以需要在最后设置
        if ($auto_increment_column) {
            $this->modifyColumn($auto_increment_column, $table_name);
        }

        $form_schema_map = [];
        foreach ($forms as $item) {
            $form_schema_map[$item['field']] = $item;
        }
        $form_schema_map = json_encode($form_schema_map, JSON_UNESCAPED_UNICODE);
        $option_name = $this->updateSchemaOption($table_name, $form_schema_map);

        return $this->json(0, $option_name);
    }


    /**
     * 一键菜单
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function crud(Request $request): Response
    {
        $table_name = $this->normalizeTableName($request->input('table'));
        if ($table_name === '') {
            throw new BusinessException('数据表不存在');
        }
        Util::checkTableName($table_name);
        $prefix = 'wa_';
        $table_basename = str_starts_with($table_name, $prefix) ? substr($table_name, strlen($prefix)) : $table_name;
        $inflector = InflectorFactory::create()->build();
        $model_class = $inflector->classify($inflector->singularize($table_basename));
        $base_path = '/app/admin';
        if ($request->method() === 'GET') {
            return view('table/crud', [
                'table' => $table_name,
                'model' => "$base_path/model/$model_class.php",
                'controller' => "$base_path/controller/{$model_class}Controller.php",
            ]);
        }
        $title = $request->post('title');
        $pid = $request->post('pid');
        $icon = $request->post('icon', '');
        $controller_input = $request->post('controller', '');
        $model_input = $request->post('model', '');
        $overwrite = $request->post('overwrite');
        if (!is_string($title) || !is_scalar($pid) || !is_string($icon) || !is_string($controller_input) || !is_string($model_input)) {
            return $this->json(1, '生成参数错误');
        }
        $controller_file = '/' . trim($controller_input, '/');
        $model_file = '/' . trim($model_input, '/');
        if ($controller_file === '/' || $model_file === '/') {
            return $this->json(1, '控制器和model不能为空');
        }

        if (str_starts_with($controller_file, $base_path) && !config('middleware.admin')) {
            return $this->json(1, "请先设置鉴权中间件：config('middleware.admin')");
        }

        $controller_info = pathinfo($controller_file);
        $model_info = pathinfo($model_file);
        $controller_path = Util::filterPath($controller_info['dirname'] ?? '');
        $model_path = Util::filterPath($model_info['dirname'] ?? '');

        $controller_file_name = Util::filterAlphaNum($controller_info['filename'] ?? '');
        $model_file_name = Util::filterAlphaNum($model_info['filename'] ?? '');

        if ($controller_info['extension'] !== 'php' || $model_info['extension'] !== 'php') {
            return $this->json(1, '控制器和model必须以.php为后缀');
        }

        $pid = (int)$pid;
        if ($pid) {
            $parent_menu = Rule::find($pid);
            if (!$parent_menu) {
                return $this->json(1, '父菜单不存在');
            }
        }

        if (!$overwrite) {
            if (is_file(base_path($controller_file))) {
                return $this->json(1, "$controller_file 已经存在");
            }
            if (is_file(base_path($model_file))) {
                return $this->json(1, "$model_file 已经存在");
            }
        }

        $explode = explode('/', trim($controller_path, '/'));
        $plugin = '';
        if (!str_contains(strtolower($controller_file), '/controller/')) {
            return $this->json(2, '控制器必须在controller目录下');
        }
        if ($explode[0] === 'plugin') {
            if (count($explode) < 4) {
                return $this->json(2, '控制器参数非法');
            }
            $plugin = $explode[1];
            if (strtolower($explode[2]) !== 'app') {
                return $this->json(2, '控制器必须在app目录');
            }
            $app = strtolower($explode[3]) !== 'controller' ? $explode[3] : '';
        } else {
            if (count($explode) < 2) {
                return $this->json(3, '控制器参数非法');
            }
            if (strtolower($explode[0]) !== 'app') {
                return $this->json(3, '控制器必须在app目录');
            }
            $app = strtolower($explode[1]) !== 'controller' ? $explode[1] : '';
        }

        Util::pauseFileMonitor();
        try {
            $model_class = $model_file_name;
            $model_namespace = str_replace('/', '\\', trim($model_path, '/'));

            // 创建model
            $this->createModel($model_class, $model_namespace, base_path($model_file), $table_name);

            $controller_suffix = $plugin ? config("plugin.$plugin.app.controller_suffix") : config('app.controller_suffix');
            $controller_class = $controller_file_name;
            $controller_namespace = str_replace('/', '\\', trim($controller_path, '/'));
            // 创建controller
            $controller_url_name = $controller_suffix && str_ends_with($controller_class, $controller_suffix) ? substr($controller_class, 0, -strlen($controller_suffix)) : $controller_class;
            $controller_url_name = str_replace('_', '-', $inflector->tableize($controller_url_name));

            if ($plugin) {
                array_splice($explode, 0, 2);
            }
            array_shift($explode);
            if ($app) {
                array_shift($explode);
            }
            foreach ($explode as $index => $item) {
                if (strtolower($item) === 'controller') {
                    unset($explode[$index]);
                }
            }

            $controller_base = implode('/', $explode);
            $controller_class_with_namespace = "$controller_namespace\\$controller_class";
            $template_path = $controller_base ? "$controller_base/$controller_url_name" : $controller_url_name;
            $this->createController($controller_class, $controller_namespace, base_path($controller_file), $model_class, $model_namespace, $title, $template_path);

            // 创建模版
            $template_file_path = ($plugin ? "/plugin/$plugin" : '') . '/app/' . ($app ? "$app/" : '') . 'view/' . $template_path;

            $model_class_with_namespace = "$model_namespace\\$model_class";
            $primary_key = (new $model_class_with_namespace)->getKeyName();
            $url_path_base = ($plugin ? "/app/$plugin/" : '/') . ($app ? "$app/" : '') . $template_path;
            $this->createTemplate(base_path($template_file_path), $table_name, $url_path_base, $primary_key, "$controller_namespace\\$controller_class");
        } finally {
            Util::resumeFileMonitor();
        }

        $menu = Rule::where('key', $controller_class_with_namespace)->first();
        if (!$menu) {
            $menu = new Rule;
        }
        $menu->pid = $pid;
        $menu->key = $controller_class_with_namespace;
        $menu->title = $title;
        $menu->icon = $icon;
        $menu->href = "$url_path_base/index";
        $menu->open_type = '_iframe';
        $menu->save();

        $role = admin('role');
        $role_ids = is_array($role) ? $role : [$role];
        $role_ids = array_filter($role_ids, static function ($role_id) {
            return is_scalar($role_id) && $role_id !== '';
        });
        $rules = Role::whereIn('id', $role_ids)->pluck('rules');
        $rule_ids = [];
        foreach ($rules as $rule_string) {
            if (!is_string($rule_string) || $rule_string === '') {
                continue;
            }
            $rule_ids = array_merge($rule_ids, explode(',', $rule_string));
        }

        // 不是超级管理员，则需要给当前管理员这个菜单的权限
        if (!in_array('*', $rule_ids) && $role) {
            $role_model = Role::find(current($role_ids));
            if ($role_model) {
                $role_model->rules .= ",{$menu->id}";
                $role_model->save();
            }
        }

        return $this->json(0);
    }

    /**
     * 创建model
     * @param $class
     * @param $namespace
     * @param $file
     * @param $table
     * @return void
     * @throws Throwable
     */
    protected function createModel($class, $namespace, $file, $table): void
    {
        $this->mkdir($file);
        $table_val = "'$table'";
        $pk = 'id';
        $properties = '';
        $timestamps = '';
        $incrementing = '';
        $columns = [];
        $database = config('database.connections')['plugin.admin.mysql']['database'];
        //plugin.admin.mysql
        foreach (Util::db()->select("select COLUMN_NAME,DATA_TYPE,COLUMN_KEY,COLUMN_COMMENT from INFORMATION_SCHEMA.COLUMNS where table_name = '$table' and table_schema = '$database' order by ORDINAL_POSITION") as $item) {
            if ($item->COLUMN_KEY === 'PRI') {
                $pk = $item->COLUMN_NAME;
                $item->COLUMN_COMMENT .= '(主键)';
                if (!str_contains(strtolower($item->DATA_TYPE), 'int')) {
                    $incrementing = <<<EOF
/**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public \$incrementing = false;

EOF;
                }
            }
            $type = $this->getType($item->DATA_TYPE);
            $properties .= " * @property $type \${$item->COLUMN_NAME} {$item->COLUMN_COMMENT}\n";
            $columns[$item->COLUMN_NAME] = $item->COLUMN_NAME;
        }
        if (!isset($columns['created_at']) || !isset($columns['updated_at'])) {
            $timestamps = <<<EOF
/**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public \$timestamps = false;

EOF;

        }
        $properties = rtrim($properties) ?: ' *';
        $model_content = <<<EOF
<?php

namespace $namespace;

use plugin\admin\app\model\Base;

/**
$properties
 */
class $class extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected \$table = $table_val;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected \$primaryKey = '$pk';
    $timestamps
    $incrementing
    
}

EOF;
        file_put_contents($file, $model_content);
    }

    /**
     * 创建控制器
     * @param $controller_class
     * @param $namespace
     * @param $file
     * @param $model_class
     * @param $model_namespace
     * @param $name
     * @param $template_path
     * @return void
     */
    protected function createController($controller_class, $namespace, $file, $model_class, $model_namespace, $name, $template_path): void
    {
        $model_class_alias = $model_class;
        if (strtolower($model_class) === strtolower($controller_class)) {
            $model_class_alias = "$model_class as {$model_class}Model";
            $model_class = "{$model_class}Model";
        }
        $this->mkdir($file);
        $controller_content = <<<EOF
<?php

namespace $namespace;

use support\Request;
use support\Response;
use $model_namespace\\$model_class_alias;
use plugin\admin\app\controller\Crud;
use support\\exception\BusinessException;

/**
 * $name 
 */
class $controller_class extends Crud
{
    
    /**
     * @var $model_class
     */
    protected \$model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        \$this->model = new $model_class;
    }
    
    /**
     * 浏览
     * @return Response
     */
    public function index(): Response
    {
        return view('$template_path/index');
    }

    /**
     * 插入
     * @param Request \$request
     * @return Response
     * @throws BusinessException
     */
    public function insert(Request \$request): Response
    {
        if (\$request->method() === 'POST') {
            return parent::insert(\$request);
        }
        return view('$template_path/insert');
    }

    /**
     * 更新
     * @param Request \$request
     * @return Response
     * @throws BusinessException
    */
    public function update(Request \$request): Response
    {
        if (\$request->method() === 'POST') {
            return parent::update(\$request);
        }
        return view('$template_path/update');
    }

}

EOF;
        file_put_contents($file, $controller_content);
    }

    /**
     * 创建控制器
     * @param $template_file_path
     * @param $table
     * @param $url_path_base
     * @param $primary_key
     * @param $controller_class_with_namespace
     * @return void
     */
    protected function createTemplate($template_file_path, $table, $url_path_base, $primary_key, $controller_class_with_namespace): void
    {

        $this->mkdir($template_file_path . '/index.html');
        $code_base = Util::controllerToUrlPath($controller_class_with_namespace);
        $code_base = str_replace('/', '.', trim($code_base, '/'));
        $form = Layui::buildForm($table, 'search');
        $html = $form->html(3);
        $html = $html ? <<<EOF
<div class="layui-card">
    <div class="layui-card-body">
        <form class="layui-form top-search-from">
            $html
            <div class="layui-form-item layui-inline">
                <label class="layui-form-label"></label>
                <button class="layui-btn layui-btn-md layui-btn-primary" lay-submit lay-filter="table-query">
                    <i class="layui-icon layui-icon-search"></i>查询
                </button>
                <button type="reset" class="layui-btn layui-btn-md" lay-submit lay-filter="table-reset">
                    <i class="layui-icon layui-icon-refresh"></i>重置
                </button>
            </div>
            <div class="toggle-btn">
                <a class="layui-hide">展开<i class="layui-icon layui-icon-down"></i></a>
                <a class="layui-hide">收起<i class="layui-icon layui-icon-up"></i></a>
            </div>
        </form>
    </div>
</div>
EOF
            : '';
        $html = str_replace("\n", "\n" . str_repeat('    ', 2), $html);
        $js = $form->js(3);

        $table_js = Layui::buildTable($table, 4);
        $template_content = <<<EOF

<!DOCTYPE html>
<html lang="zh-cn">
    <head>
        <meta charset="utf-8">
        <title>浏览页面</title>
        <link rel="stylesheet" href="/app/admin/css/style.css" />
    </head>
    <body class="pear-container">
    
        <!-- 顶部查询表单 -->
        $html
        
        <!-- 数据表格 -->
        <div class="layui-card">
            <div class="layui-card-body">
                <table id="data-table" lay-filter="data-table"></table>
            </div>
        </div>

        <!-- 表格顶部工具栏 -->
        <script type="text/html" id="table-toolbar">
            <button class="layui-btn layui-btn-primary layui-btn-md" lay-event="add" permission="$code_base.insert">
                <i class="layui-icon layui-icon-add-1"></i>新增
            </button>
            <button class="layui-btn layui-btn-danger layui-btn-md" lay-event="batchRemove" permission="$code_base.delete">
                <i class="layui-icon layui-icon-delete"></i>删除
            </button>
        </script>

        <!-- 表格行工具栏 -->
        <script type="text/html" id="table-bar">
            <button class="layui-btn layui-btn-xs tool-btn" lay-event="edit" permission="$code_base.update">编辑</button>
            <button class="layui-btn layui-btn-xs tool-btn" lay-event="remove" permission="$code_base.delete">删除</button>
        </script>

        <script src="/app/admin/component/layui/layui.js?v=2.8.12"></script>
        <script src="/app/admin/component/pear/pear.js"></script>
        <script src="/app/admin/js/permission.js"></script>
        <script src="/app/admin/js/common.js"></script>
        
        <script>

            // 相关常量
            const PRIMARY_KEY = "$primary_key";
            const SELECT_API = "$url_path_base/select";
            const UPDATE_API = "$url_path_base/update";
            const DELETE_API = "$url_path_base/delete";
            const INSERT_URL = "$url_path_base/insert";
            const UPDATE_URL = "$url_path_base/update";
            $js
            // 表格渲染
            layui.use(["table", "form", "common", "popup", "util"], function() {
                let table = layui.table;
                let form = layui.form;
                let $ = layui.$;
                let common = layui.common;
                let util = layui.util;
                $table_js
                // 编辑或删除行事件
                table.on("tool(data-table)", function(obj) {
                    if (obj.event === "remove") {
                        remove(obj);
                    } else if (obj.event === "edit") {
                        edit(obj);
                    }
                });

                // 表格顶部工具栏事件
                table.on("toolbar(data-table)", function(obj) {
                    if (obj.event === "add") {
                        add();
                    } else if (obj.event === "refresh") {
                        refreshTable();
                    } else if (obj.event === "batchRemove") {
                        batchRemove(obj);
                    }
                });

                // 表格顶部搜索事件
                form.on("submit(table-query)", function(data) {
                    table.reload("data-table", {
                        page: {
                            curr: 1
                        },
                        where: data.field
                    })
                    return false;
                });
                
                // 表格顶部搜索重置事件
                form.on("submit(table-reset)", function(data) {
                    table.reload("data-table", {
                        where: []
                    })
                });
                
                // 字段允许为空
                form.verify({
                    phone: [/(^$)|^1\d{10}$/, "请输入正确的手机号"],
                    email: [/(^$)|^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,4})+$/, "邮箱格式不正确"],
                    url: [/(^$)|(^#)|(^http(s*):\/\/[^\s]+\.[^\s]+)/, "链接格式不正确"],
                    number: [/(^$)|^\d+$/,'只能填写数字'],
                    date: [/(^$)|^(\d{4})[-\/](\d{1}|0\d{1}|1[0-2])([-\/](\d{1}|0\d{1}|[1-2][0-9]|3[0-1]))*$/, "日期格式不正确"],
                    identity: [/(^$)|(^\d{15}$)|(^\d{17}(x|X|\d)$)/, "请输入正确的身份证号"]
                });

                // 表格排序事件
                table.on("sort(data-table)", function(obj){
                    table.reload("data-table", {
                        initSort: obj,
                        scrollPos: "fixed",
                        where: {
                            field: obj.field,
                            order: obj.type
                        }
                    });
                });

                // 表格新增数据
                let add = function() {
                    layer.open({
                        type: 2,
                        title: "新增",
                        shade: 0.1,
                        maxmin: true,
                        area: [common.isModile()?"100%":"750px", common.isModile()?"100%":"625px"],
                        content: INSERT_URL
                    });
                }

                // 表格编辑数据
                let edit = function(obj) {
                    let value = obj.data[PRIMARY_KEY];
                    layer.open({
                        type: 2,
                        title: "修改",
                        shade: 0.1,
                        maxmin: true,
                        area: [common.isModile()?"100%":"750px", common.isModile()?"100%":"625px"],
                        content: UPDATE_URL + "?" + PRIMARY_KEY + "=" + value
                    });
                }

                // 删除一行
                let remove = function(obj) {
                    return doRemove(obj.data[PRIMARY_KEY]);
                }

                // 删除多行
                let batchRemove = function(obj) {
                    let checkIds = common.checkField(obj, PRIMARY_KEY);
                    if (checkIds === "") {
                        layui.popup.warning("未选中数据");
                        return false;
                    }
                    doRemove(checkIds.split(","));
                }

                // 执行删除
                let doRemove = function (ids) {
                    let data = {};
                    data[PRIMARY_KEY] = ids;
                    layer.confirm("确定删除?", {
                        icon: 3,
                        title: "提示"
                    }, function(index) {
                        layer.close(index);
                        let loading = layer.load();
                        $.ajax({
                            url: DELETE_API,
                            data: data,
                            dataType: "json",
                            type: "post",
                            success: function(res) {
                                layer.close(loading);
                                if (res.code) {
                                    return layui.popup.failure(res.msg);
                                }
                                return layui.popup.success("操作成功", refreshTable);
                            }
                        })
                    });
                }

                // 刷新表格数据
                window.refreshTable = function() {
                    table.reloadData("data-table", {
                        scrollPos: "fixed",
                        done: function (res, curr) {
                            if (curr > 1 && res.data && !res.data.length) {
                                curr = curr - 1;
                                table.reloadData("data-table", {
                                    page: {
                                        curr: curr
                                    },
                                })
                            }
                        }
                    });
                }
            })

        </script>
    </body>
</html>

EOF;
        file_put_contents("$template_file_path/index.html", $template_content);

        $form = Layui::buildForm($table);

        $html = $form->html(5);
        $js = $form->js(3);
        $template_content = <<<EOF
<!DOCTYPE html>
<html lang="zh-cn">
    <head>
        <meta charset="UTF-8">
        <title>新增页面</title>
        <link rel="stylesheet" href="/app/admin/css/style.css" />
        <link rel="stylesheet" href="/app/admin/component/jsoneditor/css/jsoneditor.css" />
        <link rel="stylesheet" href="/app/admin/css/form-box.css" />
    </head>
    <body>

        <form class="layui-form" action="">

            <div class="mainBox">
                <div class="main-container mr-5">
                    $html
                </div>
            </div>

            <div class="bottom">
                <div class="button-container">
                    <button type="submit" class="layui-btn layui-btn-primary layui-btn-md" lay-submit=""
                        lay-filter="save">
                        提交
                    </button>
                    <button type="reset" class="layui-btn layui-btn-md">
                        重置
                    </button>
                </div>
            </div>
            
        </form>

        <script src="/app/admin/component/layui/layui.js?v=2.8.12"></script>
        <script src="/app/admin/component/pear/pear.js"></script>
        <script src="/app/admin/component/jsoneditor/jsoneditor.js"></script>
        <script src="/app/admin/js/permission.js"></script>
        
        <script>
            
            // 相关接口
            const INSERT_API = "$url_path_base/insert";
            $js
            //提交事件
            layui.use(["form", "popup"], function () {
                // 字段验证允许为空
                layui.form.verify({
                    phone: [/(^$)|^1\d{10}$/, "请输入正确的手机号"],
                    email: [/(^$)|^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,4})+$/, "邮箱格式不正确"],
                    url: [/(^$)|(^#)|(^http(s*):\/\/[^\s]+\.[^\s]+)/, "链接格式不正确"],
                    number: [/(^$)|^\d+$/,'只能填写数字'],
                    date: [/(^$)|^(\d{4})[-\/](\d{1}|0\d{1}|1[0-2])([-\/](\d{1}|0\d{1}|[1-2][0-9]|3[0-1]))*$/, "日期格式不正确"],
                    identity: [/(^$)|(^\d{15}$)|(^\d{17}(x|X|\d)$)/, "请输入正确的身份证号"]
                });
                layui.form.on("submit(save)", function (data) {
                    layui.$.ajax({
                        url: INSERT_API,
                        type: "POST",
                        dateType: "json",
                        data: data.field,
                        success: function (res) {
                            if (res.code) {
                                return layui.popup.failure(res.msg);
                            }
                            return layui.popup.success("操作成功", function () {
                                parent.refreshTable();
                                parent.layer.close(parent.layer.getFrameIndex(window.name));
                            });
                        }
                    });
                    return false;
                });
            });

        </script>

    </body>
</html>

EOF;

        file_put_contents("$template_file_path/insert.html", $template_content);

        $form = Layui::buildForm($table, 'update');


        $html = $form->html(5);
        $js = $form->js(6);
        $template_content = <<<EOF
<!DOCTYPE html>
<html lang="zh-cn">
    <head>
        <meta charset="UTF-8">
        <title>更新页面</title>
        <link rel="stylesheet" href="/app/admin/css/style.css" />
        <link rel="stylesheet" href="/app/admin/component/jsoneditor/css/jsoneditor.css" />
        <link rel="stylesheet" href="/app/admin/css/form-box.css" />
    </head>
    <body>

        <form class="layui-form">

            <div class="mainBox">
                <div class="main-container mr-5">
                    $html
                </div>
            </div>

            <div class="bottom">
                <div class="button-container">
                    <button type="submit" class="layui-btn layui-btn-primary layui-btn-md" lay-submit="" lay-filter="save">
                        提交
                    </button>
                    <button type="reset" class="layui-btn layui-btn-md">
                        重置
                    </button>
                </div>
            </div>
            
        </form>

        <script src="/app/admin/component/layui/layui.js?v=2.8.12"></script>
        <script src="/app/admin/component/pear/pear.js"></script>
        <script src="/app/admin/component/jsoneditor/jsoneditor.js"></script>
        <script src="/app/admin/js/permission.js"></script>
        
        <script>

            // 相关接口
            const PRIMARY_KEY = "$primary_key";
            const SELECT_API = "$url_path_base/select" + location.search;
            const UPDATE_API = "$url_path_base/update";
            // 获取数据库记录
            layui.use(["form", "util", "popup"], function () {
                let $ = layui.$;
                $.ajax({
                    url: SELECT_API,
                    dataType: "json",
                    success: function (res) {
                        
                        // 给表单初始化数据
                        layui.each(res.data[0], function (key, value) {
                            let obj = $('*[name="'+key+'"]');
                            if (key === "password") {
                                obj.attr("placeholder", "不更新密码请留空");
                                return;
                            }
                            if (typeof obj[0] === "undefined" || !obj[0].nodeName) return;
                            if (obj[0].nodeName.toLowerCase() === "textarea") {
                                obj.val(value);
                            } else {
                                obj.attr("value", value);
                                obj[0].value = value;
                            }
                            
                            // 多图渲染
                            if (obj[0].classList.contains('uploader-list')) {
                                let multiple_images = value.split(",");
                                $.each(multiple_images, function(index, value) {
                                    $('#uploader-list-'+ key).append(
                                        '<div class="file-iteme">' +
                                        '<div class="handle"><i class="layui-icon layui-icon-delete"></i></div>' +
                                        '<img src='+value +' alt="'+ value +'" >' +
                                        '</div>'
                                    );
                                });
                            }
                        });
                        $js
                        
                        // ajax返回失败
                        if (res.code) {
                            layui.popup.failure(res.msg);
                        }
                        
                    }
                });
            });

            //提交事件
            layui.use(["form", "popup"], function () {
                // 字段验证允许为空
                layui.form.verify({
                    phone: [/(^$)|^1\d{10}$/, "请输入正确的手机号"],
                    email: [/(^$)|^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,4})+$/, "邮箱格式不正确"],
                    url: [/(^$)|(^#)|(^http(s*):\/\/[^\s]+\.[^\s]+)/, "链接格式不正确"],
                    number: [/(^$)|^\d+$/,'只能填写数字'],
                    date: [/(^$)|^(\d{4})[-\/](\d{1}|0\d{1}|1[0-2])([-\/](\d{1}|0\d{1}|[1-2][0-9]|3[0-1]))*$/, "日期格式不正确"],
                    identity: [/(^$)|(^\d{15}$)|(^\d{17}(x|X|\d)$)/, "请输入正确的身份证号"]
                });
                layui.form.on("submit(save)", function (data) {
                    data.field[PRIMARY_KEY] = layui.url().search[PRIMARY_KEY];
                    layui.$.ajax({
                        url: UPDATE_API,
                        type: "POST",
                        dateType: "json",
                        data: data.field,
                        success: function (res) {
                            if (res.code) {
                                return layui.popup.failure(res.msg);
                            }
                            return layui.popup.success("操作成功", function () {
                                parent.refreshTable();
                                parent.layer.close(parent.layer.getFrameIndex(window.name));
                            });
                        }
                    });
                    return false;
                });
            });

        </script>

    </body>

</html>

EOF;

        file_put_contents("$template_file_path/update.html", $template_content);

    }

    /**
     * 创建目录
     * @param $file
     * @return void
     */
    protected function mkdir($file): void
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }


    /**
     * 查询记录
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function select(Request $request): Response
    {
        $page = $request->get('page', 1);
        $field = $request->get('field');
        $order = $request->get('order', 'asc');
        $table = $this->normalizeTableName($request->get('table', ''));
        $format = $request->get('format', 'normal');
        $limit = $request->get('limit', $format === 'tree' ? 5000 : 10);

        $allow_column = Util::db()->select("desc `$table`");
        if (!$allow_column) {
            return $this->json(2, '表不存在');
        }
        $allow_column = array_column($allow_column, 'Field', 'Field');
        if (!in_array($field, $allow_column)) {
            $field = current($allow_column);
        }
        $order = $order === 'asc' ? 'asc' : 'desc';
        $paginator = Util::db()->table($table);
        foreach ($request->get() as $column => $value) {
            if ($value === '') {
                continue;
            }
            if (isset($allow_column[$column])) {
                if (is_array($value)) {
                    if (!$this->isValidQueryCondition($value)) {
                        continue;
                    }
                    if ($value[0] === 'like') {
                        $paginator = $paginator->where($column, 'like', "%$value[1]%");
                    } elseif (in_array($value[0], ['>', '=', '<', '<>', 'not like'])) {
                        $paginator = $paginator->where($column, $value[0], $value[1]);
                    } else {
                        if ($value[0] !== '' || $value[1] !== '') {
                            $paginator = $paginator->whereBetween($column, $value);
                        }
                    }
                } else {
                    $paginator = $paginator->where($column, $value);
                }
            }
        }
        $paginator = $paginator->orderBy($field, $order)->paginate($limit, '*', 'page', $page);
        $items = $paginator->items();
        if ($format == 'tree') {
            $items_map = [];
            foreach ($items as $item) {
                $items_map[$item->id] = (array)$item;
            }
            $formatted_items = [];
            foreach ($items_map as $item) {
                if ($item['pid'] && isset($items_map[$item['pid']])) {
                    $items_map[$item['pid']]['children'][] = &$item;
                }
            }
            foreach ($items_map as $item) {
                if (!$item['pid']) {
                    $formatted_items[] = $item;
                }
            }
            $items = $formatted_items;
        }

        return json(['code' => 0, 'msg' => 'ok', 'count' => $paginator->total(), 'data' => $items]);

    }

    /**
     * 插入记录
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'GET') {
            $table = $this->normalizeTableName($request->get('table'));
            $form = Layui::buildForm($table);
            return view('table/insert', [
                'form' => $form,
                'table' => $table
            ]);
        }
        $table = $this->normalizeTableName($request->input('table', ''));
        $data = $request->post();
        $allow_column = Util::db()->select("desc `$table`");
        if (!$allow_column) {
            throw new BusinessException('表不存在', 2);
        }
        $columns = array_column($allow_column, 'Type', 'Field');
        foreach ($data as $col => $item) {
            if (!isset($columns[$col])) {
                unset($data[$col]);
                continue;
            }
            // 非字符串类型传空则为null
            if ($item === '' && !str_contains(strtolower($columns[$col]), 'varchar') && !str_contains(strtolower($columns[$col]), 'text')) {
                $data[$col] = null;
            }
            if (is_array($item)) {
                $data[$col] = $this->normalizeRecordInputValue($item);
                $item = $data[$col];
            }
            if ($col === 'password') {
                if (!is_scalar($data[$col]) && $data[$col] !== null) {
                    throw new BusinessException('密码参数错误');
                }
                $data[$col] = Util::passwordHash($item);
            }
        }
        $datetime = date('Y-m-d H:i:s');
        if (isset($columns['created_at']) && empty($data['created_at'])) {
            $data['created_at'] = $datetime;
        }
        if (isset($columns['updated_at']) && empty($data['updated_at'])) {
            $data['updated_at'] = $datetime;
        }
        $id = Util::db()->table($table)->insertGetId($data);
        return $this->json(0, (string)$id);
    }

    /**
     * 更新记录
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function update(Request $request): Response
    {
        if ($request->method() === 'GET') {
            $table = $this->normalizeTableName($request->get('table'));
            $table_info = Util::getSchema($table, 'table');
            $primary_key = $table_info['primary_key'][0] ?? null;
            $raw_value = $request->get($primary_key, '');
            $value = is_scalar($raw_value) ? htmlspecialchars((string)$raw_value) : '';
            $form = Layui::buildForm($table, 'update');
            return view('table/update', [
                'primary_key' => $primary_key,
                'value' => $value,
                'form' => $form,
                'table' => $table
            ]);
        }
        $table = $this->normalizeTableName($request->post('table'));
        $table_info = Util::getSchema($table, 'table');
        $primary_keys = $table_info['primary_key'];
        if (empty($primary_keys)) {
            return $this->json(1, '该表没有主键，无法执行更新操作');
        }
        if (count($primary_keys) > 1) {
            return $this->json(1, '不支持复合主键更新');
        }
        $primary_key = $primary_keys[0];
        $value = $request->post($primary_key);
        if (!is_scalar($value) && $value !== null) {
            return $this->json(1, '主键参数错误');
        }
        $data = $request->post();
        $allow_column = Util::db()->select("desc `$table`");
        if (!$allow_column) {
            throw new BusinessException('表不存在', 2);
        }
        $columns = array_column($allow_column, 'Type', 'Field');
        foreach ($data as $col => $item) {
            if (!isset($columns[$col])) {
                unset($data[$col]);
                continue;
            }
            // 非字符串类型传空则为null
            if ($item === '' && !str_contains(strtolower($columns[$col]), 'varchar') && !str_contains(strtolower($columns[$col]), 'text')) {
                $data[$col] = null;
            }
            if (is_array($item)) {
                $data[$col] = $this->normalizeRecordInputValue($item);
                $item = $data[$col];
            }
            if ($col === 'password') {
                if (!is_scalar($data[$col]) && $data[$col] !== null) {
                    throw new BusinessException('密码参数错误');
                }
                // 密码为空，则不更新密码
                if ($item == '') {
                    unset($data[$col]);
                    continue;
                }
                $data[$col] = Util::passwordHash($item);
            }
        }
        $datetime = date('Y-m-d H:i:s');
        if (isset($columns['updated_at']) && empty($data['updated_at'])) {
            $data['updated_at'] = $datetime;
        }
        Util::db()->table($table)->where($primary_key, $value)->update($data);
        return $this->json(0);
    }

    /**
     * 归一化记录表单字段值
     * @param mixed $item 字段值
     * @return mixed
     * @throws BusinessException
     */
    protected function normalizeRecordInputValue(mixed $item): mixed
    {
        if (!is_array($item)) {
            return $item;
        }
        foreach ($item as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new BusinessException('参数错误');
            }
        }
        return implode(',', $item);
    }

    /**
     * 归一化数据表名
     * @param mixed $table 表名
     * @return string
     * @throws BusinessException
     */
    protected function normalizeTableName(mixed $table): string
    {
        if (!is_string($table)) {
            return '';
        }
        return Util::filterAlphaNum($table);
    }

    /**
     * 归一化表结构提交数据
     * @param array $data 表结构数据
     * @return array
     * @throws BusinessException
     */
    protected function normalizeStructureInput(array $data): array
    {
        $table_name = $this->normalizeTableName($data['table'] ?? '');
        $table_comment = $this->scalarTableValue($data['table_comment'] ?? '');
        $columns = $data['columns'] ?? [];
        $forms = $data['forms'] ?? [];
        $keys = $data['keys'] ?? [];
        if (!is_array($columns) || !is_array($forms) || !is_array($keys)) {
            throw new BusinessException('表结构参数错误');
        }
        return [
            $table_name,
            $table_comment,
            $this->normalizeColumnDefinitions($columns),
            $this->normalizeFormDefinitions($forms),
            $this->normalizeIndexDefinitions($keys),
        ];
    }

    /**
     * 归一化字段定义
     * @param array $columns 字段定义
     * @return array
     * @throws BusinessException
     */
    protected function normalizeColumnDefinitions(array $columns): array
    {
        $result = [];
        foreach ($columns as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = $this->scalarTableValue($item['field'] ?? '');
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            $item['field'] = Util::filterAlphaNum($field);
            $item['old_field'] = $this->scalarTableValue($item['old_field'] ?? '');
            $item['type'] = $this->scalarTableValue($item['type'] ?? '');
            $item['length'] = $this->scalarTableValue($item['length'] ?? '');
            $item['comment'] = $this->scalarTableValue($item['comment'] ?? '');
            $item['nullable'] = !empty($item['nullable']);
            $item['primary_key'] = !empty($item['primary_key']);
            $item['auto_increment'] = !empty($item['auto_increment']);
            $item['default'] = $item['default'] ?? null;
            if ($item['default'] !== null && !is_scalar($item['default'])) {
                throw new BusinessException('表结构参数错误');
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 归一化表单定义
     * @param array $forms 表单定义
     * @return array
     * @throws BusinessException
     */
    protected function normalizeFormDefinitions(array $forms): array
    {
        $result = [];
        foreach ($forms as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = trim($this->scalarTableValue($item['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $item['field'] = Util::filterAlphaNum($field);
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 归一化索引定义
     * @param array $keys 索引定义
     * @return array
     * @throws BusinessException
     */
    protected function normalizeIndexDefinitions(array $keys): array
    {
        $result = [];
        foreach ($keys as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim($this->scalarTableValue($item['name'] ?? ''));
            $columns = $this->normalizeIndexColumns($item['columns'] ?? '');
            if ($name === '' || $columns === '') {
                continue;
            }
            $item['name'] = Util::filterAlphaNum($name);
            $item['old_name'] = $this->scalarTableValue($item['old_name'] ?? '');
            $item['columns'] = $columns;
            $item['type'] = ($item['type'] ?? '') === 'unique' ? 'unique' : 'index';
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 归一化索引字段
     * @param mixed $columns 索引字段
     * @return string
     * @throws BusinessException
     */
    protected function normalizeIndexColumns(mixed $columns): string
    {
        if (is_array($columns)) {
            $items = [];
            foreach ($columns as $column) {
                $column = trim($this->scalarTableValue($column));
                if ($column !== '') {
                    $items[] = Util::filterAlphaNum($column);
                }
            }
            return implode(',', $items);
        }
        if (!is_scalar($columns)) {
            throw new BusinessException('表结构参数错误');
        }
        $items = [];
        foreach (explode(',', (string)$columns) as $column) {
            $column = trim($column);
            if ($column !== '') {
                $items[] = Util::filterAlphaNum($column);
            }
        }
        return implode(',', $items);
    }

    /**
     * 归一化数据表名列表
     * @param mixed $tables 表名列表
     * @return array
     * @throws BusinessException
     */
    protected function normalizeTableNameList(mixed $tables): array
    {
        $result = [];
        foreach ((array)$tables as $table) {
            $table = $this->normalizeTableName($table);
            if ($table !== '') {
                $result[] = $table;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * 归一化表结构标量值
     * @param mixed $value 原始值
     * @param string $default 默认值
     * @return string
     */
    protected function scalarTableValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * 删除记录
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function delete(Request $request): Response
    {
        $table = $this->normalizeTableName($request->post('table', ''));
        $table_info = Util::getSchema($table, 'table');
        $primary_keys = $table_info['primary_key'];
        if (empty($primary_keys)) {
            return $this->json(1, '该表没有主键，无法执行删除操作');
        }
        if (count($primary_keys) > 1) {
            return $this->json(1, '不支持复合主键删除');
        }
        $primary_key = $primary_keys[0];
        $value = (array)$request->post($primary_key);
        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                return $this->json(1, '主键参数错误');
            }
        }
        Util::db()->table($table)->whereIn($primary_key, $value)->delete();
        return $this->json(0);
    }

    /**
     * 判断查询条件是否可安全参与构造
     * @param mixed $value 查询条件
     * @return bool
     */
    protected function isValidQueryCondition(mixed $value): bool
    {
        if (!is_array($value)) {
            return is_scalar($value) || $value === null;
        }
        if (!isset($value[0]) || !is_scalar($value[0])) {
            return false;
        }
        if (!isset($value[1])) {
            return false;
        }
        if (is_array($value[1])) {
            foreach ($value[1] as $item) {
                if (!is_scalar($item) && $item !== null) {
                    return false;
                }
            }
            return false;
        }
        return is_scalar($value[1]);
    }


    /**
     * 删除表
     * @param Request $request
     * @return Response
     */
    public function drop(Request $request): Response
    {
        $tables = $this->normalizeTableNameList($request->post('tables'));
        if (!$tables) {
            return $this->json(0, 'not found');
        }
        $prefix = 'wa_';
        $table_not_allow_drop = ["{$prefix}users", "{$prefix}options", "{$prefix}roles", "{$prefix}rules", "{$prefix}uploads"];
        if ($found = array_intersect($tables, $table_not_allow_drop)) {
            return $this->json(400, implode(',', $found) . '不允许删除');
        }
        foreach ($tables as $table) {
            Util::schema()->drop($table);
            // 删除schema
            Util::db()->table('wa_options')->where('name', "table_form_schema_$table")->delete();
        }
        return $this->json(0);
    }

    /**
     * 表摘要
     * @param Request $request
     * @return Response
     */
    public function schema(Request $request): Response
    {
        $table = $this->normalizeTableName($request->get('table'));
        $data = Util::getSchema($table);

        return $this->json(0, 'ok', [
            'table' => $data['table'],
            'columns' => array_values($data['columns']),
            'forms' => array_values($data['forms']),
            'keys' => array_values($data['keys']),
        ]);
    }

    /**
     * 创建字段
     * @param mixed $column 字段定义
     * @param Blueprint $table
     * @return mixed
     */
    protected function createColumn(mixed $column, Blueprint $table): mixed
    {
        $method = Util::filterAlphaNum($this->scalarTableValue($column['type'] ?? ''));
        if ($method === '') {
            throw new BusinessException('表结构参数错误');
        }
        $column['field'] = $this->scalarTableValue($column['field'] ?? '');
        $column['length'] = $this->scalarTableValue($column['length'] ?? '');
        $column['comment'] = $this->scalarTableValue($column['comment'] ?? '');
        $column['nullable'] = !empty($column['nullable']);
        $column['primary_key'] = !empty($column['primary_key']);
        $column['auto_increment'] = !empty($column['auto_increment']);
        $column['default'] = $column['default'] ?? null;
        $args = [$column['field']];
        if (stripos($method, 'int') !== false) {
            // auto_increment 会自动成为主键
            if ($column['auto_increment']) {
                $column['nullable'] = false;
                $column['default'] = null;
                $args[] = true;
            }
        } elseif (in_array($method, ['string', 'char']) || stripos($method, 'time') !== false) {
            if ($column['length']) {
                $args[] = $column['length'];
            }
        } elseif ($method === 'enum') {
            $args[] = array_map('trim', explode(',', $column['length']));
        } elseif (in_array($method, ['float', 'decimal', 'double'])) {
            if ($column['length']) {
                $args = array_merge($args, array_map('trim', explode(',', $column['length'])));
            }
        } else {
            $column['auto_increment'] = false;
        }

        $column_def = call_user_func_array([$table, $method], $args);
        if (!empty($column['comment'])) {
            $column_def = $column_def->comment($column['comment']);
        }

        if (!$column['auto_increment'] && $column['primary_key']) {
            $column_def = $column_def->primary(true);
        }

        if ($column['auto_increment'] && !$column['primary_key']) {
            $column_def = $column_def->primary(false);
        }
        $column_def = $column_def->nullable($column['nullable']);

        if ($column['primary_key']) {
            $column_def = $column_def->nullable(false);
        }

        if ($method != 'text' && $column['default'] !== null) {
            $column_def->default($column['default']);
        }
        return $column_def;
    }

    /**
     * 更改字段
     * @param $column
     * @param $table
     * @return void
     * @throws BusinessException
     */
    protected function modifyColumn($column, $table): void
    {
        $table = Util::filterAlphaNum($table);
        $method = Util::filterAlphaNum($this->scalarTableValue($column['type'] ?? ''));
        $field = Util::filterAlphaNum($this->scalarTableValue($column['field'] ?? ''));
        $old_field = Util::filterAlphaNum($this->scalarTableValue($column['old_field'] ?? ''));
        $nullable = !empty($column['nullable']);
        $default = ($column['default'] ?? null) !== null ? Util::pdoQuote($column['default']) : null;
        $comment = Util::pdoQuote($this->scalarTableValue($column['comment'] ?? ''));
        $auto_increment = !empty($column['auto_increment']);
        $length_raw = $this->scalarTableValue($column['length'] ?? '0', '0');
        $length = (int)$length_raw;

        if (!empty($column['primary_key'])) {
            $default = null;
        }

        if ($old_field && $old_field !== $field) {
            $sql = "ALTER TABLE `$table` CHANGE COLUMN `$old_field` `$field` ";
        } else {
            $sql = "ALTER TABLE `$table` MODIFY `$field` ";
        }

        if (stripos($method, 'integer') !== false) {
            $type = str_ireplace('integer', 'int', $method);
            if (stripos($method, 'unsigned') !== false) {
                $type = str_ireplace('unsigned', '', $type);
                $sql .= "$type ";
                $sql .= 'unsigned ';
            } else {
                $sql .= "$type ";
            }
            if ($auto_increment) {
                $column['nullable'] = false;
                $column['default'] = null;
                $sql .= 'AUTO_INCREMENT ';
            }
        } else {
            switch ($method) {
                case 'string':
                    $length = $length ?: 255;
                    $sql .= "varchar($length) ";
                    break;
                case 'char':
                case 'time':
                    $sql .= $length ? "$method($length) " : "$method ";
                    break;
                case 'enum':
                    $args = array_map('trim', explode(',', $length_raw));
                    foreach ($args as $key => $value) {
                        $args[$key] = Util::pdoQuote($value);
                    }
                    $sql .= 'enum(' . implode(',', $args) . ') ';
                    break;
                case 'double':
                case 'float':
                case 'decimal':
                    if (trim($length_raw)) {
                        $args = array_map('intval', explode(',', $length_raw));
                        $args[1] = $args[1] ?? $args[0];
                        $sql .= "$method($args[0], $args[1]) ";
                        break;
                    }
                    $sql .= "$method ";
                    break;
                default :
                    $sql .= "$method ";

            }
        }

        if (!$nullable) {
            $sql .= 'NOT NULL ';
        }

        if ($method != 'text' && $default !== null) {
            $sql .= "DEFAULT $default ";
        }

        $sql .= "COMMENT $comment ";

        Util::db()->statement($sql);
    }

    /**
     * 字段类型列表
     * @param Request $request
     * @return Response
     */
    public function types(Request $request): Response
    {
        $types = Util::methodControlMap();
        return $this->json(0, 'ok', $types);
    }


    /**
     * 更新表的form schema信息
     * @param $table_name
     * @param $data
     * @return string
     */
    protected function updateSchemaOption($table_name, $data): string
    {
        $option_name = "table_form_schema_$table_name";
        $option = Option::where('name', $option_name)->first();
        if ($option) {
            Option::where('name', $option_name)->update(['value' => $data]);
        } else {
            Option::insert(['name' => $option_name, 'value' => $data]);
        }
        return $option_name;
    }

    /**
     * 字段类型到php类型映射
     * @param string $type
     * @return string
     */
    protected function getType(string $type): string
    {
        if (str_contains($type, 'int')) {
            return 'integer';
        }
        return match ($type) {
            'varchar', 'string', 'text', 'date', 'time', 'guid', 'datetimetz', 'datetime', 'decimal', 'enum' => 'string',
            'boolean' => 'integer',
            'float' => 'float',
            default => 'mixed',
        };
    }

}
