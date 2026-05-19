<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <title>应用插件</title>
    <link rel="stylesheet" href="/app/admin/component/pear/css/pear.css" />
    <link rel="stylesheet" href="/app/admin/admin/css/reset.css" />
</head>
<body class="pear-container">
<div class="layui-card">
    <div class="layui-card-body">
        <form class="layui-form top-search-from">
            <div class="layui-form-item layui-inline">
                <label class="layui-form-label">名称</label>
                <div class="layui-input-inline">
                    <input type="text" name="name" value="" class="layui-input">
                </div>
            </div>
            <div class="layui-form-item layui-inline">
                <button class="pear-btn pear-btn-md pear-btn-primary" lay-submit lay-filter="table-query">
                    <i class="layui-icon layui-icon-search"></i>查询
                </button>
                <button type="reset" class="pear-btn pear-btn-md" lay-submit lay-filter="table-reset">
                    <i class="layui-icon layui-icon-refresh"></i>重置
                </button>
            </div>
        </form>
    </div>
</div>

<div class="layui-card">
    <div class="layui-card-body">
        <table id="data-table" lay-filter="data-table"></table>
    </div>
</div>

<script type="text/html" id="table-toolbar">
    <button class="pear-btn pear-btn-primary pear-btn-md" lay-event="refresh">
        <i class="layui-icon layui-icon-refresh"></i>刷新
    </button>
    <button class="pear-btn pear-btn-success pear-btn-md" lay-event="import">
        <i class="layui-icon layui-icon-upload"></i>导入
    </button>
</script>

<script type="text/html" id="table-bar">
    @{{# if(d.installed){ }}
    @{{# var hasUpdate = d.releases && d.releases.length > 0 && d.releases[d.releases.length - 1] !== d.installed; }}
    @{{# if(hasUpdate){ }}
    <button class="pear-btn pear-btn-xs pear-btn-warm" lay-event="update">
        <i class="layui-icon layui-icon-refresh-3"></i>更新
    </button>
    @{{# } }}
    <button class="pear-btn pear-btn-xs" lay-event="export">
        <i class="layui-icon layui-icon-export"></i>导出
    </button>
    @{{# if(d.name !== 'admin'){ }}
    <button class="pear-btn pear-btn-xs" lay-event="uninstall">
        <i class="layui-icon layui-icon-delete"></i>卸载
    </button>
    @{{# } }}
    @{{# } else { }}
    <button class="pear-btn pear-btn-xs" lay-event="install">
        <i class="layui-icon layui-icon-app"></i>安装
    </button>
    @{{# } }}
</script>

<!-- 导入弹窗模板 -->
<div id="import-dialog" style="display:none;padding:20px;">
    <div class="layui-form">
        <div class="layui-form-item" style="text-align:center;">
            <div class="layui-upload-drag" id="upload-area" style="display:inline-block;width:400px;box-sizing:border-box;">
                <i class="layui-icon layui-icon-upload"></i>
                <p>点击上传，或将 ZIP 文件拖拽到此处</p>
            </div>
        </div>
        <div class="layui-form-item" style="margin-top:15px;text-align:center;">
            <button type="button" class="layui-btn" id="do-upload">
                <i class="layui-icon layui-icon-upload"></i>开始导入
            </button>
        </div>
    </div>
</div>

<script src="/app/admin/component/layui/layui.js?v=2.8.12"></script>
<script src="/app/admin/component/pear/pear.js"></script>
<script src="/app/admin/admin/js/common.js"></script>
<script>
    const LIST_API = "/app/admin/plugin/list";
    const INSTALL_API = "/app/admin/plugin/install";
    const UNINSTALL_API = "/app/admin/plugin/uninstall";
    const EXPORT_API = "/app/admin/plugin/export";
    const IMPORT_API = "/app/admin/plugin/import";
    const LOGIN_URL = "/app/admin/plugin/login";

    layui.use(["table", "form", "common", "popup", "util", "upload", "layer"], function() {
        let table = layui.table;
        let form = layui.form;
        let $ = layui.$;
        let layer = layui.layer;
        let upload = layui.upload;
        let uploadIndex = null;
        let selectedFile = null;

        let cols = [
            { field: "name", title: "名称", width: 150, templet: function(d) {
                    var name = layui.util.escape(d.name || '');
                    if (d.url) {
                        var url = layui.util.escape(d.url);
                        return '<a href="' + url + '" target="_blank" style="color:#1e9fff;">' + name + '</a>';
                    }
                    return name;
                }},
            { field: "title", title: "标题", minWidth: 250 },
            { field: "author", title: "作者", width: 120 },
            { field: "price", title: "价格", width: 80 },
            { field: "version", title: "版本", width: 90 },
            { title: "操作", toolbar: "#table-bar", align: "center", width: 320 },
        ];

        table.render({
            elem: "#data-table",
            url: LIST_API,
            page: true,
            limit: 20,
            cols: [cols],
            skin: "line",
            size: "lg",
            toolbar: "#table-toolbar",
            defaultToolbar: [{ title: "刷新", layEvent: "refresh", icon: "layui-icon-refresh" }],
        });

        table.on("tool(data-table)", function(obj) {
            let data = obj.data;
            if (obj.event === "install") {
                installPlugin(data);
            } else if (obj.event === "update") {
                updatePlugin(data);
            } else if (obj.event === "export") {
                exportPlugin(data);
            } else if (obj.event === "uninstall") {
                uninstallPlugin(data);
            }
        });

        table.on("toolbar(data-table)", function(obj) {
            if (obj.event === "refresh") {
                table.reload("data-table");
            } else if (obj.event === "import") {
                showImportDialog();
            }
        });

        form.on("submit(table-query)", function(data) {
            table.reload("data-table", {
                page: { curr: 1 },
                where: data.field
            });
            return false;
        });

        form.on("submit(table-reset)", function(data) {
            table.reload("data-table", { where: [] });
        });

        function installPlugin(data) {
            layer.confirm("确定安装 " + data.name + " 插件?", { icon: 3, title: "提示" }, function(index) {
                layer.close(index);
                let loading = layer.load();
                $.ajax({
                    url: INSTALL_API,
                    data: { name: data.name, version: data.version },
                    dataType: "json",
                    type: "post",
                    success: function(res) {
                        layer.close(loading);
                        if (res.code === -1) {
                            layer.open({
                                type: 2, title: "登录", shade: 0.1,
                                area: ["400px", "300px"],
                                content: LOGIN_URL
                            });
                        } else if (res.code) {
                            layui.popup.failure(res.msg);
                        } else {
                            layui.popup.success("安装成功", function() {
                                table.reload("data-table");
                            });
                        }
                    }
                });
            });
        }

        function updatePlugin(data) {
            var latestVersion = data.releases && data.releases.length > 0 ? data.releases[data.releases.length - 1] : data.version;
            layer.confirm("确定将 " + data.name + " 插件从 " + data.installed + " 更新到 " + latestVersion + "?", { icon: 3, title: "更新确认" }, function(index) {
                layer.close(index);
                let loading = layer.load();
                $.ajax({
                    url: INSTALL_API,
                    data: { name: data.name, version: latestVersion },
                    dataType: "json",
                    type: "post",
                    success: function(res) {
                        layer.close(loading);
                        if (res.code === -1) {
                            layer.open({
                                type: 2, title: "登录", shade: 0.1,
                                area: ["400px", "300px"],
                                content: LOGIN_URL
                            });
                        } else if (res.code) {
                            layui.popup.failure(res.msg);
                        } else {
                            layui.popup.success("更新成功", function() {
                                table.reload("data-table");
                            });
                        }
                    }
                });
            });
        }

        function exportPlugin(data) {
            window.location.href = EXPORT_API + "?name=" + data.name;
        }

        function uninstallPlugin(data) {
            layer.confirm("确定卸载 " + data.name + " 插件?", { icon: 3, title: "提示" }, function(index) {
                layer.close(index);
                let loading = layer.load();
                $.ajax({
                    url: UNINSTALL_API,
                    data: { name: data.name, version: data.version },
                    dataType: "json",
                    type: "post",
                    success: function(res) {
                        layer.close(loading);
                        if (res.code) {
                            layui.popup.failure(res.msg);
                        } else {
                            layui.popup.success("卸载成功", function() {
                                table.reload("data-table");
                            });
                        }
                    }
                });
            });
        }

        function showImportDialog() {
            selectedFile = null;
            $("#upload-area").html('<i class="layui-icon layui-icon-upload"></i><p>点击上传，或将 ZIP 文件拖拽到此处</p>');
            uploadIndex = layer.open({
                type: 1,
                title: "导入本地插件",
                shade: 0.1,
                area: ["450px", "auto"],
                content: $("#import-dialog").html(),
                success: function(layero, index) {
                    let $dialog = $(layero);
                    upload.render({
                        elem: $dialog.find("#upload-area")[0],
                        url: IMPORT_API,
                        auto: false,
                        accept: "file",
                        exts: "zip",
                        size: 102400,
                        choose: function(obj) {
                            obj.preview(function(index, file, result) {
                                selectedFile = file;
                                var fileName = layui.util.escape(file.name);
                                $dialog.find("#upload-area").html('<i class="layui-icon layui-icon-ok-circle" style="color:#36b368;font-size:50px;"></i><p style="color:#36b368;">已选择: ' + fileName + '</p><p style="color:#999;">(' + (file.size / 1024).toFixed(1) + ' KB)</p>');
                            });
                        }
                    });
                    $dialog.find("#do-upload").on("click", function() {
                        if (!selectedFile) {
                            layer.msg("请先选择 ZIP 文件", { icon: 2 });
                            return;
                        }
                        let $form = $('<form enctype="multipart/form-data" style="display:none;"></form>');
                        $dialog.find("input[type=file]").appendTo($form);
                        $("body").append($form);
                        $.ajax({
                            url: IMPORT_API,
                            type: "post",
                            data: new FormData($form[0]),
                            processData: false,
                            contentType:false,
                            dataType: "json",
                            xhr: function() {
                                let xhr = new XMLHttpRequest();
                                xhr.upload.addEventListener("progress", function(e) {
                                    if (e.lengthComputable) {
                                        let percent = Math.round((e.loaded / e.total) * 100);
                                        layer.msg("上传中... " + percent + "%", { icon: 16, shade: 0.3, time: 0 });
                                    }
                                });
                                return xhr;
                            },
                            success: function(res) {
                                layer.closeAll("loading");
                                if (res.code === 0) {
                                    layer.close(uploadIndex);
                                    layui.popup.success("导入成功", function() {
                                        table.reload("data-table");
                                    });
                                } else {
                                    layui.popup.failure(res.msg || "导入失败");
                                }
                                $form.remove();
                            },
                            error: function() {
                                layer.closeAll("loading");
                                layui.popup.failure("上传失败");
                                $form.remove();
                            }
                        });
                    });
                }
            });
        }
    });
</script>
</body>
</html>