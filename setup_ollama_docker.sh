#!/bin/bash

MODEL="hf.co/mradermacher/Llama-3.1-8B-Instruct-MedQA-GGUF:Q6_K"
CONTAINER_NAME="ollama"
PORT=11434
VOLUME_NAME="ollama_data"

echo "🔍 بررسی نصب Docker..."

# بررسی وجود Docker
if ! command -v docker &> /dev/null; then
    echo "🚀 Docker نصب نیست. در حال نصب..."
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        sudo apt update -y
        sudo apt install -y docker.io
        sudo systemctl enable docker
        sudo systemctl start docker
    else
        echo "❌ نصب خودکار Docker فقط برای Linux پشتیبانی می‌شود."
        exit 1
    fi
else
    echo "✅ Docker از قبل نصب است."
fi

# بررسی وجود volume
if ! docker volume ls | grep -q "$VOLUME_NAME"; then
    echo "💾 ایجاد volume برای نگهداری مدل‌ها..."
    docker volume create $VOLUME_NAME
else
    echo "✅ volume موجود است: $VOLUME_NAME"
fi

# بررسی وجود کانتینر
if docker ps -a --format '{{.Names}}' | grep -q "^$CONTAINER_NAME$"; then
    echo "⚙️ کانتینر Ollama از قبل وجود دارد."
else
    echo "🚀 در حال ساخت کانتینر Ollama..."
    docker run -d \
      --name $CONTAINER_NAME \
      -v $VOLUME_NAME:/root/.ollama \
      -p $PORT:11434 \
      ollama/ollama
    sleep 5
fi

# بررسی اجرای کانتینر
if ! docker ps --format '{{.Names}}' | grep -q "^$CONTAINER_NAME$"; then
    echo "🟢 اجرای کانتینر Ollama..."
    docker start $CONTAINER_NAME
else
    echo "✅ کانتینر در حال اجرا است."
fi

# بررسی وجود مدل
echo "🔍 بررسی وجود مدل $MODEL ..."
if ! docker exec $CONTAINER_NAME ollama list | grep -q "$MODEL"; then
    echo "⬇️ در حال دانلود مدل..."
    docker exec $CONTAINER_NAME ollama pull "$MODEL"
else
    echo "✅ مدل قبلاً نصب شده است."
fi

# اجرای تست
echo "🤖 اجرای مدل برای تست..."
docker exec -i $CONTAINER_NAME ollama run "$MODEL" -p "Hello! I am a medical assistant. How can I help you?"

echo "🎉 عملیات با موفقیت انجام شد!"
