@echo off
chcp 65001 >nul
rem ============================================
rem 一键部署入口（Windows）
rem 实际执行 deploy.sh（需要 Git Bash，随 Git for Windows 自带）
rem 用法: deploy.bat v1.1.0
rem ============================================
cd /d "%~dp0"

where bash >nul 2>nul
if errorlevel 1 (
    echo [错误] 未找到 bash，请安装 Git for Windows 后重试
    echo       下载: https://git-scm.com/download/win
    pause
    exit /b 1
)

if "%~1"=="" (
    echo [用法] deploy.bat ^<版本号^>
    echo [示例] deploy.bat v1.1.0
    pause
    exit /b 1
)

bash deploy.sh %*
pause
