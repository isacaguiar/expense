@echo off
setlocal

set JASYPT_JAR=jasypt-1.9.3.jar

if not exist %JASYPT_JAR% (
    echo [ERRO] O arquivo %JASYPT_JAR% nao foi encontrado.
    pause
    exit /b
)

if not exist JasyptEncryptorTool.class (
    echo Compilando JasyptEncryptorTool.java...
    javac -cp %JASYPT_JAR% JasyptEncryptorTool.java
)

java -cp .;%JASYPT_JAR% JasyptEncryptorTool
pause