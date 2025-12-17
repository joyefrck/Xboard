#!/bin/bash
# Xboard 服务器端快速更新脚本
# 在生产服务器上执行此脚本以拉取最新代码

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}════════════════════════════════════════${NC}"
echo -e "${BLUE}   Xboard 服务器更新工具${NC}"
echo -e "${BLUE}════════════════════════════════════════${NC}"
echo ""

# 检查是否在Git仓库中
if [ ! -d ".git" ]; then
    echo -e "${RED}❌ 错误：当前目录不是Git仓库${NC}"
    exit 1
fi

# 检查Git是否已安装
if ! command -v git &> /dev/null; then
    echo -e "${RED}❌ 错误：Git未安装！${NC}"
    exit 1
fi

# 设置安全目录
git config --global --add safe.directory $(pwd)

echo -e "${BLUE}🔍 检查当前状态...${NC}"
BRANCH=$(git branch --show-current)
echo -e "  当前分支: ${GREEN}$BRANCH${NC}"
echo ""

# 显示远程最新提交
echo -e "${BLUE}📡 获取远程更新...${NC}"
git fetch origin

# 检查是否有更新
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/$BRANCH)

if [ $LOCAL = $REMOTE ]; then
    echo -e "${GREEN}✅ 代码已是最新版本${NC}"
    echo ""
    read -p "是否仍要执行缓存清理? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 0
    fi
else
    echo -e "${YELLOW}📦 发现新的更新：${NC}"
    git log HEAD..origin/$BRANCH --oneline --no-decorate | head -5
    echo ""
    
    read -p "确认拉取最新代码? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${RED}❌ 取消更新${NC}"
        exit 1
    fi
    
    echo ""
    echo -e "${BLUE}🚀 开始更新流程...${NC}"
    echo ""
    
    # 备份当前代码（可选）
    BACKUP_DIR="backups/$(date +%Y%m%d_%H%M%S)"
    echo -e "${YELLOW}[1/6]${NC} 创建备份..."
    # mkdir -p $BACKUP_DIR
    # cp -r . $BACKUP_DIR/ 2>/dev/null || true
    echo -e "  ${GREEN}备份位置: $BACKUP_DIR${NC}"
    
    # 拉取代码
    echo -e "${YELLOW}[2/6]${NC} 拉取最新代码..."
    git reset --hard origin/$BRANCH
    git pull origin $BRANCH
    
    # 更新Composer依赖（如果composer.json有变化）
    if git diff HEAD@{1} --name-only | grep -q "composer.json"; then
        echo -e "${YELLOW}[3/6]${NC} 检测到依赖变化，更新Composer..."
        if [ ! -f "composer.phar" ]; then
            wget -q https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
        fi
        php composer.phar update --no-dev --optimize-autoloader
    else
        echo -e "${YELLOW}[3/6]${NC} 跳过Composer更新（无变化）"
    fi
fi

# 清理缓存
echo -e "${YELLOW}[4/6]${NC} 清理视图缓存..."
php artisan view:clear

echo -e "${YELLOW}[5/6]${NC} 清理应用缓存..."
php artisan cache:clear

# 重启服务
echo -e "${YELLOW}[6/6]${NC} 重启服务..."

# 检测是否使用Docker
if [ -f "docker-compose.yaml" ] || [ -f "docker-compose.yml" ]; then
    echo -e "  检测到Docker环境，重启容器..."
    docker compose restart
elif pgrep -f "artisan octane:start" > /dev/null; then
    echo -e "  检测到Octane进程，重新加载..."
    php artisan octane:reload
else
    echo -e "  ${YELLOW}⚠️  未检测到Octane或Docker，请手动重启服务${NC}"
fi

# 设置权限（如果需要）
if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
    chown -R www:www $(pwd) 2>/dev/null || true
fi

if [ -d ".docker/.data" ]; then
    chmod -R 777 .docker/.data 2>/dev/null || true
fi

echo ""
echo -e "${GREEN}════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ 更新完成！${NC}"
echo -e "${GREEN}════════════════════════════════════════${NC}"
echo ""
echo -e "${BLUE}🔍 验证清单：${NC}"
echo -e "  □ 访问网站确认可正常访问"
echo -e "  □ 检查最新功能是否生效"
echo -e "  □ 清除浏览器缓存后测试"
echo ""
