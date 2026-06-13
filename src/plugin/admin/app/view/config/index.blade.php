<!DOCTYPE html>
<html lang="zh-cn">
    <head>
        <meta charset="UTF-8">
        <title></title>
        <link rel="stylesheet" href="/app/admin/css/style.css" />
    </head>
    <body class="pear-container">
        <style>
            .config-panel {
                max-width: 980px;
            }
            .config-form {
                max-width: 760px;
            }
            .config-form .layui-input,
            .config-form .layui-textarea,
            .config-form .xm-select-parent {
                width: 100%;
                max-width: 560px;
                box-sizing: border-box;
            }
            .config-logo {
                width: 100px;
                height: 100px;
                object-fit: contain;
                border: 1px solid #eee;
                border-radius: 10px;
                background: #f8f8f8;
                display: block;
                margin-bottom: 10px;
            }
            .config-actions,
            .logo-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            @media (max-width: 768px) {
                body.pear-container {
                    padding: 10px;
                }
                .layui-card-body {
                    padding: 12px;
                }
                .layui-tab-content {
                    padding-left: 0;
                    padding-right: 0;
                }
                .config-form {
                    max-width: none;
                }
                .config-form .layui-form-label {
                    float: none;
                    display: block;
                    width: auto;
                    padding: 0 0 8px;
                    text-align: left;
                    line-height: 1.4;
                }
                .config-form .layui-input-block {
                    margin-left: 0;
                    min-height: auto;
                }
                .config-form .layui-input,
                .config-form .layui-textarea,
                .config-form .xm-select-parent {
                    max-width: none;
                }
                .config-actions .layui-btn,
                .logo-actions .layui-btn {
                    flex: 1 1 120px;
                    margin-left: 0;
                }
            }
        </style>

        <div class="layui-card config-panel">
            <div class="layui-card-body">

                <div class="layui-tab layui-tab-brief">
                <ul class="layui-tab-title">
                    <li class="layui-this">基本信息</li>
                    <li>菜单配置</li>
                    <li>页面配置</li>
                </ul>
                <div class="layui-tab-content">

                    <!-- 基本信息 -->
                    <div class="layui-tab-item layui-show">

                        <form class="layui-form config-form" lay-filter="baseInfo">
                            <div class="layui-form-item">
                                <label class="layui-form-label">网站名称</label>
                                <div class="layui-input-block">
                                    <input type="text" name="title" required  lay-verify="required" placeholder="请输入网站名称" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">网站Logo</label>
                                <div class="layui-input-block">
                                    <img class="config-logo" id="config-logo-preview" src="/app/admin/admin/images/logo.png"/>
                                    <input type="text" style="display:none" name="image" value="/app/admin/admin/images/logo.png" />
                                    <div class="logo-actions">
                                        <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="image" permission="app.admin.upload.avatar">
                                            <i class="layui-icon layui-icon-upload"></i>上传图片
                                        </button>
                                        <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="attachment-choose-image" permission="app.admin.upload.attachment">
                                            <i class="layui-icon layui-icon-align-left"></i>选择图片
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">ICP备案号</label>
                                <div class="layui-input-block">
                                    <input type="text" name="icp"  placeholder="请输入ICP备案号" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">公安网备</label>
                                <div class="layui-input-block">
                                    <input type="text" name="beian" placeholder="请输入公安网备号" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">其它</label>
                                <div class="layui-input-block">
                                    <textarea name="footer_txt" placeholder="其它展示在页面底部的内容" autocomplete="off" class="layui-textarea"></textarea>
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <div class="layui-input-block config-actions">
                                    <button type="submit" class="layui-btn layui-btn-primary layui-btn-md" lay-submit="" lay-filter="saveBaseInfo">
                                        提交
                                    </button>
                                </div>
                            </div>
                        </form>

                    </div>

                    <!-- 菜单设置 -->
                    <div class="layui-tab-item">

                        <form class="layui-form config-form" lay-filter="menuInfo">
                            <div class="layui-form-item">
                                <label class="layui-form-label">菜单url</label>
                                <div class="layui-input-block">
                                    <input type="text" name="data" required  lay-verify="required" placeholder="请输入菜单url" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">默认菜单ID</label>
                                <div class="layui-input-block">
                                    <input type="number" name="select" required  lay-verify="required" placeholder="请输入默认菜单ID" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">开启手风琴</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="accordion" id="accordion" lay-skin="switch" />
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">折叠菜单</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" id="collapse" name="collapse" lay-skin="switch" />
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <div class="layui-input-block config-actions">
                                    <button type="submit" class="layui-btn layui-btn-primary layui-btn-md" lay-submit="" lay-filter="saveMenuInfo">
                                        提交
                                    </button>
                                </div>
                            </div>
                        </form>

                    </div>

                    <!-- tab设置 -->
                    <div class="layui-tab-item">

                        <form class="layui-form config-form" lay-filter="tabInfo">

                            <div class="layui-form-item">
                                <label class="layui-form-label">保持标签</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="keepState" lay-skin="switch" />
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">记住标签</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="session" lay-skin="switch" />
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">预加载标签</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="preload" lay-skin="switch" />
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <label class="layui-form-label">最大标签数</label>
                                <div class="layui-input-block">
                                    <input type="number" name="max" required  lay-verify="required" placeholder="请输入最大标签数" autocomplete="off" class="layui-input">
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <label class="layui-form-label">主标签标题</label>
                                <div class="layui-input-block">
                                    <input type="text" name="title" required  lay-verify="required" placeholder="请输入主页标签标题" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">主标签URL</label>
                                <div class="layui-input-block">
                                    <input type="text" name="href" required  lay-verify="required" placeholder="请输入菜单url" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">主标签ID</label>
                                <div class="layui-input-block">
                                    <input type="number" name="id" required  lay-verify="required" placeholder="请输入主页标签ID" autocomplete="off" class="layui-input">
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <div class="layui-input-block config-actions">
                                    <button type="submit" class="layui-btn layui-btn-primary layui-btn-md" lay-submit="" lay-filter="saveTabInfo">
                                        提交
                                    </button>
                                </div>
                            </div>
                        </form>

                    </div>

                </div>
            </div>

            </div>
        </div>


        <script src="/app/admin/component/layui/layui.js?v=2.8.12"></script>
        <script src="/app/admin/component/pear/pear.js"></script>
        <script src="/app/admin/js/common.js"></script>
        <script src="/app/admin/js/permission.js"></script>
        <script>
            const DEFAULT_LOGO = "/app/admin/admin/images/logo.png";

            // 基础设置
            layui.use(["upload", "layer", "popup"], function() {
                let $ = layui.$;
                let form = layui.form;
                let imageInput = $('input[name="image"]');
                let imagePreview = $("#config-logo-preview");

                // image
                layui.$("#attachment-choose-image").on("click", function() {
                    parent.layer.open({
                        type: 2,
                        title: "选择附件",
                        shade: 0.1,
                        maxmin: true,
                        content: "/app/admin/upload/attachment?ext=jpg,jpeg,png,gif,bmp,svg",
                        area: ["95%", "90%"],
                        success: function (layero, index) {
                            parent.layui.$("#layui-layer" + index).data("callback", function (data) {
                                imageInput.val(data.url);
                                imagePreview.attr("src", data.url || DEFAULT_LOGO);
                            });
                        }
                    });
                });
                layui.upload.render({
                    elem: "#image",
                    url: "/app/admin/upload/avatar",
                    acceptMime: "image/gif,image/jpeg,image/jpg,image/png,image/svg+xml",
                    done: function (res) {
                        if (res.code) {
                            return layui.popup.failure(res.msg);
                        }
                        imageInput.val(res.data.url);
                        imagePreview.attr("src", res.data.url || DEFAULT_LOGO);
                    }
                });

                // 提交
                form.on("submit(saveBaseInfo)", function(data){
                    $.ajax({
                        url: "/app/admin/config/update",
                        dataType: "json",
                        type: "POST",
                        data: {logo: data.field},
                        success: function (res) {
                            if (res.code) {
                                return layui.popup.failure(res.msg);
                            }
                            top.layui && top.layui.admin.configurationProvider().then(conf=>{
                                top.layui.admin.logoRender(conf)
                            })
                            return layui.popup.success("操作成功");
                        }
                    });
                    return false;
                });
            });

            // 菜单设置
            layui.use(["upload", "layer", "popup"], function() {
                let $ = layui.$;
                let form = layui.form;
                // 提交
                form.on("submit(saveMenuInfo)", function(data){
                    $.ajax({
                        url: "/app/admin/config/update",
                        dataType: "json",
                        type: "POST",
                        data: {menu: data.field},
                        success: function (res) {
                            if (res.code) {
                                return layui.popup.failure(res.msg);
                            }
                            return layui.popup.success("操作成功");
                        }
                    });
                    return false;
                });
            });

            // 标签设置
            layui.use(["upload", "layer", "popup"], function() {
                let $ = layui.$;
                let form = layui.form;
                // 提交
                form.on("submit(saveTabInfo)", function(data){
                    let field = data.field;
                    field.index = {
                        id: field.id,
                        href: field.href,
                        title: field.title,
                    };
                    delete data.field;
                    $.ajax({
                        url: "/app/admin/config/update",
                        dataType: "json",
                        type: "POST",
                        data: {tab: field},
                        success: function (res) {
                            if (res.code) {
                                return layui.popup.failure(res.msg);
                            }
                            return layui.popup.success("操作成功");
                        }
                    });
                    // 删除sessionStorage缓存
                    sessionStorage.clear();
                    return false;
                });
            });

            layui.use(["form"], function () {
                let form = layui.form;
                let $ = layui.$;
                let imageInput = $('input[name="image"]');
                let imagePreview = $("#config-logo-preview");
                $.ajax({
                    url: "/app/admin/config/get",
                    dataType: "json",
                    success: function (res) {
                        if (res.code) {
                            return layui.popup.failure(res.msg);
                        }
                        form.val("baseInfo", res.logo);
                        imageInput.val(res.logo.image || "");
                        imagePreview.attr("src", res.logo.image || DEFAULT_LOGO);
                        form.val("menuInfo", res.menu);
                        let tab = res.tab;
                        let index = tab.index;
                        delete tab.index;
                        tab.id = index.id;
                        tab.title = index.title;
                        tab.href= index.href;
                        form.val("tabInfo", res.tab);
                    }
                });

            });

        </script>

    </body>
</html>
