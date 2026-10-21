@echo off
rem Kodmob canlı paketi: build [--no-content] [--bump major^|minor^|patch] [--skip-tests]  (bkz. docs\build.md)
node "%~dp0build.mjs" %*
