@echo off
cd /d %~dp0\..
python python\live_to_mysql.py --loop 300
pause
