<!DOCTYPE html>
<html lang="zh-cn">
    <head>
        <meta charset="UTF-8">
        <title></title>
        <link rel="stylesheet" href="/app/admin/css/style.css" />
        <link rel="stylesheet" href="/app/admin/css/form-box.css" />
    </head>
    <body class="pear-container">
        <style>
            .account-panel {
                max-width: 980px;
            }
            .account-form {
                max-width: 720px;
            }
            .account-form .layui-input,
            .account-form .layui-textarea,
            .account-form .xm-select-parent,
            .account-form xm-select {
                width: 100%;
                max-width: 520px;
                box-sizing: border-box;
            }
            .account-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .account-avatar {
                width: 96px;
                height: 96px;
                object-fit: cover;
                border-radius: 12px;
                border: 1px solid #eee;
                display: block;
                margin-bottom: 10px;
                background: #f8f8f8;
            }
            .avatar-actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .readonly-group .layui-input {
                background-color: #f8f8f8;
                color: #666;
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
                .account-form {
                    max-width: none;
                }
                .account-form .layui-form-label {
                    float: none;
                    display: block;
                    width: auto;
                    padding: 0 0 8px;
                    text-align: left;
                    line-height: 1.4;
                }
                .account-form .layui-input-block {
                    margin-left: 0;
                    min-height: auto;
                }
                .account-form .layui-input,
                .account-form .layui-textarea,
                .account-form .xm-select-parent,
                .account-form xm-select {
                    max-width: none;
                }
                .account-actions .layui-btn,
                .avatar-actions .layui-btn {
                    flex: 1 1 120px;
                    margin-left: 0;
                }
            }
        </style>

        <div class="layui-card account-panel">
            <div class="layui-card-body">

                <div class="layui-tab layui-tab-brief">
                <ul class="layui-tab-title">
                    <li class="layui-this">基本信息</li>
                    <li>安全设置</li>
                </ul>
                <div class="layui-tab-content">

                    <!-- 基本信息 -->
                    <div class="layui-tab-item layui-show">

                        <form class="layui-form account-form" lay-filter="baseInfo">
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">用户名</label>
                                <div class="layui-input-block">
                                    <input type="text" name="username" value="" readonly class="layui-input">
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <label class="layui-form-label">头像</label>
                                <div class="layui-input-block">
                                    <img class="account-avatar" id="avatar-preview" src=""/>
                                    <input type="text" style="display:none" name="avatar" value="" />
                                    <div class="avatar-actions">
                                        <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="avatar">
                                            <i class="layui-icon layui-icon-upload"></i>上传图片
                                        </button>
                                        <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="attachment-choose-avatar">
                                            <i class="layui-icon layui-icon-align-left"></i>选择图片
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <label class="layui-form-label">昵称</label>
                                <div class="layui-input-block">
                                    <input type="text" name="nickname" required  lay-verify="required" placeholder="请输入昵称" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">性别</label>
                                <div class="layui-input-block">
                                    <div name="sex" id="sex" value="" ></div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">邮箱</label>
                                <div class="layui-input-block">
                                    <input type="text" name="email" placeholder="请输入邮箱" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">联系电话</label>
                                <div class="layui-input-block">
                                    <input type="text" name="mobile" placeholder="请输入联系电话" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">生日</label>
                                <div class="layui-input-block">
                                    <input type="text" name="birthday" id="birthday" placeholder="请选择生日" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">个人简介</label>
                                <div class="layui-input-block">
                                    <textarea name="bio" placeholder="请输入个人简介" class="layui-textarea"></textarea>
                                </div>
                            </div>

                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">等级</label>
                                <div class="layui-input-block">
                                    <input type="text" name="level" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">余额(元)</label>
                                <div class="layui-input-block">
                                    <input type="text" name="money" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">积分</label>
                                <div class="layui-input-block">
                                    <input type="text" name="score" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">角色</label>
                                <div class="layui-input-block">
                                    <input type="hidden" name="role" value="">
                                    <input type="text" name="role_name" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">状态</label>
                                <div class="layui-input-block">
                                    <input type="hidden" name="status" value="">
                                    <input type="text" name="status_text" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">登录时间</label>
                                <div class="layui-input-block">
                                    <input type="text" name="last_time" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">登录IP</label>
                                <div class="layui-input-block">
                                    <input type="text" name="last_ip" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">创建时间</label>
                                <div class="layui-input-block">
                                    <input type="text" name="created_at" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item readonly-group">
                                <label class="layui-form-label">更新时间</label>
                                <div class="layui-input-block">
                                    <input type="text" name="updated_at" value="" readonly class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <div class="layui-input-block account-actions">
                                    <button type="submit" class="layui-btn layui-btn-primary layui-btn-md" lay-submit="" lay-filter="saveBaseInfo">
                                        提交
                                    </button>
                                    <button type="reset" class="layui-btn layui-btn-md">
                                        重置
                                    </button>
                                </div>
                            </div>
                        </form>

                    </div>

                    <div class="layui-tab-item">

                        <form class="layui-form account-form" action="">
                            <div class="layui-form-item">
                                <label class="layui-form-label">原始密码</label>
                                <div class="layui-input-block">
                                    <input type="password" name="old_password" required  lay-verify="required" placeholder="请输入原始密码" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">新密码</label>
                                <div class="layui-input-block">
                                    <input type="password" name="password" required  lay-verify="required" placeholder="请输入新密码" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">确认新密码</label>
                                <div class="layui-input-block">
                                    <input type="password" name="password_confirm" required  lay-verify="required" placeholder="请再次输入新密码" autocomplete="off" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <div class="layui-input-block account-actions">
                                    <button type="submit" class="layui-btn layui-btn-primary layui-btn-md" lay-submit="" lay-filter="savePassword">
                                        提交
                                    </button>
                                    <button type="reset" class="layui-btn layui-btn-md">
                                        重置
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

            layui.use(['form', 'popup', 'upload', 'layer', 'laydate', 'xmSelect'], function () {
                let form = layui.form;
                let $ = layui.$;
                let avatarInput = $('input[name="avatar"]');
                const DEFAULT_AVATAR = "/app/admin/avatar.png";

                function renderSex(value) {
                    $.ajax({
                        url: "/app/admin/dict/get/sex",
                        dataType: "json",
                        success: function (res) {
                            let initValue = value ? [String(value)] : [];
                            layui.xmSelect.render({
                                el: "#sex",
                                name: "sex",
                                initValue: initValue,
                                data: res.data,
                                model: {"icon":"hidden","label":{"type":"text"}},
                                clickClose: true,
                                radio: true,
                            });
                            if (res.code) {
                                layui.popup.failure(res.msg);
                            }
                        }
                    });
                }

                layui.laydate.render({
                    elem: "#birthday",
                });

                $("#attachment-choose-avatar").on("click", function() {
                    parent.layer.open({
                        type: 2,
                        title: "选择附件",
                        shade: 0.1,
                        maxmin: true,
                        content: "/app/admin/upload/attachment?ext=jpg,jpeg,png,gif,bmp,webp",
                        area: ["95%", "90%"],
                        success: function (layero, index) {
                            parent.layui.$("#layui-layer" + index).data("callback", function (data) {
                                avatarInput.val(data.url);
                                $("#avatar-preview").attr("src", data.url);
                            });
                        }
                    });
                });

                layui.upload.render({
                    elem: "#avatar",
                    url: "/app/admin/upload/avatar",
                    acceptMime: "image/gif,image/jpeg,image/jpg,image/png,image/webp",
                    field: "__file__",
                    done: function (res) {
                        if (res.code > 0) return layui.layer.msg(res.msg);
                        avatarInput.val(res.data.url);
                        $("#avatar-preview").attr("src", res.data.url);
                    }
                });

                $.ajax({
                    url: "/app/admin/account/info",
                    dataType: "json",
                    success: function (res) {
                        if (res.code) {
                            return layui.popup.failure(res.msg || "获取账户信息失败");
                        }
                        form.val("baseInfo", res.data);
                        $("#avatar-preview").attr("src", res.data.avatar || DEFAULT_AVATAR);
                        renderSex(res.data.sex);
                    }
                });

                form.on("submit(saveBaseInfo)", function(data){
                    $.ajax({
                        url: "/app/admin/account/update",
                        dataType: "json",
                        type: "POST",
                        data: data.field,
                        success: function (res) {
                            if (res.code) {
                                return layui.popup.failure(res.msg);
                            }
                            setTimeout(function () {
                              top.location.reload();
                            }, 1000)
                            return layui.popup.success("操作成功");
                        }
                    });
                    return false;
                });

                form.on("submit(savePassword)", function(data){
                    $.ajax({
                        url: "/app/admin/account/password",
                        dataType: "json",
                        type: "POST",
                        data: data.field,
                        success: function (res) {
                            if (res.code) {
                                return layui.popup.failure(res.msg);
                            }
                            setTimeout(()=>{
                                top.location.reload();
                            }, 2000)
                            return layui.popup.success("操作成功");
                        }
                    });
                    return false;
                });

            });

        </script>

    </body>
</html>
