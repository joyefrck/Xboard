#!/bin/bash
# Xboard 生产环境快速部署脚本

set -e

echo "🔍 检查需要提交的文件..."
echo ""

# 显示待提交的文件
git status --short

echo ""
echo "📦 需要提交的关键文件："
echo "  ✓ theme/Xboard/dashboard.blade.php (修改)"
echo "  ✓ public/home_logo.jpeg (新增)"
echo "  ✓ public/login_logo.jpeg (新增)"
echo "  ✓ public/sidebar_logo.png (新增)"
echo ""

read -p "是否继续提交并推送到Git仓库? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]
then
    echo "❌ 取消部署"
    exit 1
fi

echo ""
echo "📝 添加文件到Git..."
git add theme/Xboard/dashboard.blade.php
git add public/home_logo.jpeg
git add public/login_logo.jpeg
git add public/sidebar_logo.png

echo ""
echo "💾 提交更改..."
git commit -m "feat: 添加登录页logo、侧边栏logo和鼠标滑动特效

- 新增登录页面鼠标滑动涟漪效果
- 新增登录页面logo显示
- 新增用户端侧边栏logo显示
- 上传logo静态资源文件
"

echo ""
echo "🚀 推送到远程仓库..."
git push origin master

echo ""
echo "✅ 代码已推送到Git仓库！"
echo ""
echo "📋 后续步骤："
echo "  1. SSH登录到生产服务器"
echo "  2. 执行: cd /path/to/xboard && git pull origin master"
echo "  3. 执行: php artisan view:clear && php artisan cache:clear"
echo "  4. 如果使用Octane: php artisan octane:reload"
echo "  5. 清除浏览器缓存并验证功能"
echo ""
echo "🔗 验证清单:"
echo "  □ 登录页面显示logo"
echo "  □ 登录页面有鼠标滑动效果"  
echo "  □ 用户端首页左上角显示logo"
echo "  □ 侧边栏显示logo"
echo ""
