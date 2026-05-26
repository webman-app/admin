<?php
namespace Webman\Admin;

class Install
{
    const WEBMAN_PLUGIN = true;

    /**
     * @var array
     */
    protected static $pathRelation = array (
        'plugin/admin' => 'plugin/admin',
    );

    /**
     * Install
     * @return void
     */
    public static function install()
    {
        static::installByRelation();
    }

    /**
     * Uninstall
     * @return void
     */
    public static function uninstall()
    {
        // 备份需要保留的配置文件
        $configPath = base_path() . '/plugin/admin/config';
        $preserveFiles = ['database.php', 'thinkorm.php'];
        $preserved = [];

        foreach ($preserveFiles as $file) {
            $filePath = $configPath . '/' . $file;
            if (is_file($filePath)) {
                $preserved[$file] = file_get_contents($filePath);
            }
        }

        self::uninstallByRelation();

        // 恢复保留的配置文件
        if (!empty($preserved)) {
            if (!is_dir($configPath)) {
                mkdir($configPath, 0755, true);
            }
            foreach ($preserved as $file => $content) {
                file_put_contents($configPath . '/' . $file, $content);
                echo "Preserve config/$file\n";
            }
        }
    }

    /**
     * installByRelation
     * @return void
     */
    public static function installByRelation()
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parent_dir = base_path().'/'.substr($dest, 0, $pos);
                if (!is_dir($parent_dir)) {
                    mkdir($parent_dir, 0755, true);
                }
            }
            //symlink(__DIR__ . "/$source", base_path()."/$dest");
            copy_dir(__DIR__ . "/$source", base_path()."/$dest", true);
            echo "Create $dest
";
        }
    }

    /**
     * uninstallByRelation
     * @return void
     */
    public static function uninstallByRelation()
    {
        foreach (static::$pathRelation as $source => $dest) {
            $path = base_path()."/$dest";
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            echo "Remove $dest
";
            if (is_file($path) || is_link($path)) {
                unlink($path);
                continue;
            }
            remove_dir($path);
        }
    }

}