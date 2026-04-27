@echo off
REM Starts the optional Python live collector from the project root.
cd /d %~dp0\..
REM Poll every 300 seconds and write new NSW parking snapshots into MySQL.
python python\live_to_mysql.py --loop 300
pause
