#!/bin/bash

JASYPT_JAR="jasypt-1.9.3.jar"

if [ ! -f "$JASYPT_JAR" ]; then
  echo "[ERRO] Arquivo $JASYPT_JAR nao encontrado."
  exit 1
fi

if [ ! -f "JasyptEncryptorTool.class" ]; then
  echo "Compilando JasyptEncryptorTool.java..."
  javac -cp "$JASYPT_JAR" JasyptEncryptorTool.java
fi

java -cp ".:$JASYPT_JAR" JasyptEncryptorTool