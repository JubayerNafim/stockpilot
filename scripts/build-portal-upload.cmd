@echo off
rem Double-click wrapper: builds portal-upload.zip via Git Bash.
where bash >nul 2>nul
if errorlevel 1 (
    echo Git Bash was not found on PATH. Install "Git for Windows" (git-scm.com)
    echo or run the build from Claude Code with:  bash scripts/build-portal-upload.sh
    pause
    exit /b 1
)
bash "%~dp0build-portal-upload.sh"
echo.
pause
