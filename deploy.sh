#!/bin/bash
# Xboard 通用生产部署脚本
# 适用于GitHub仓库的快速发布流程

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}════════════════════════════════════════${NC}"
echo -e "${BLUE}   Xboard 生产环境部署工具${NC}"
echo -e "${BLUE}════════════════════════════════════════${NC}"
echo ""

# 检查是否在Git仓库中
if [ ! -d ".git" ]; then
    echo -e "${RED}❌ 错误：当前目录不是Git仓库${NC}"
    exit 1
fi

# 检查是否有未提交的更改
if [[ -z $(git status -s) ]]; then
    echo -e "${YELLOW}⚠️  没有检测到任何更改${NC}"
    echo ""
    read -p "是否要从远程仓库拉取最新代码? (y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git pull origin master
        echo -e "${GREEN}✅ 代码已更新${NC}"
    fi
    exit 0
fi

echo -e "${BLUE}🔍 检测到以下更改：${NC}"
echo ""
git status --short
echo ""

# 统计更改
MODIFIED=$(git status --short | grep -c "^ M" || true)
ADDED=$(git status --short | grep -c "^??" || true)
DELETED=$(git status --short | grep -c "^ D" || true)

echo -e "${BLUE}📊 更改统计：${NC}"
echo -e "  ${GREEN}修改文件: ${MODIFIED}${NC}"
echo -e "  ${GREEN}新增文件: ${ADDED}${NC}"
echo -e "  ${RED}删除文件: ${DELETED}${NC}"
echo ""

# 询问提交信息
echo -e "${YELLOW}📝 请输入本次更新的描述（留空使用默认描述）：${NC}"
read -p "> " COMMIT_MSG

if [ -z "$COMMIT_MSG" ]; then
    COMMIT_MSG="chore: 代码优化和功能更新"
fi

echo ""
echo -e "${BLUE}准备提交的信息：${NC}"
echo -e "${GREEN}$COMMIT_MSG${NC}"
echo ""

# 确认提交
read -p "确认提交并推送到GitHub? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}❌ 取消部署${NC}"
    exit 1
fi

echo ""
echo -e "${BLUE}🚀 开始部署流程...${NC}"
echo ""

# 添加所有更改
echo -e "${YELLOW}[1/3]${NC} 添加文件到Git..."
git add .

# 提交更改
echo -e "${YELLOW}[2/3]${NC} 提交更改..."
git commit -m "$COMMIT_MSG"

# 推送到GitHub
echo -e "${YELLOW}[3/3]${NC} 推送到GitHub远程仓库..."
BRANCH=$(git branch --show-current)
git push origin $BRANCH

echo ""
echo -e "${GREEN}════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ 代码已成功推送到GitHub！${NC}"
echo -e "${GREEN}════════════════════════════════════════${NC}"
echo ""
echo -e "${BLUE}📋 接下来在生产服务器执行：${NC}"
echo ""
echo -e "${YELLOW}方式一：使用自动更新脚本（推荐）${NC}"
echo -e "  cd /path/to/xboard && bash update.sh"
echo ""
echo -e "${YELLOW}方式二：手动更新${NC}"
echo -e "  1. cd /path/to/xboard"
echo -e "  2. git pull origin $BRANCH"
echo -e "  3. php artisan view:clear"
echo -e "  4. php artisan cache:clear"
echo -e "  5. php artisan octane:reload"
echo -e "     ${GREEN}（或 Docker用户执行: docker compose restart）${NC}"
echo ""
echo -e "${BLUE}🔗 快速SSH连接命令示例：${NC}"
echo -e "  ${GREEN}ssh user@your-server.com \"cd /path/to/xboard && bash update.sh\"${NC}"
echo ""
