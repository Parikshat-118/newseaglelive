FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1

WORKDIR /app

RUN apt-get update && apt-get install -y \
    gcc \
    g++ \
    build-essential \
    default-libmysqlclient-dev \
    pkg-config \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

COPY telegram-bot/requirements.txt .

RUN pip install --upgrade pip && \
    pip install --no-cache-dir -r requirements.txt

COPY telegram-bot/ .

RUN mkdir -p logs

CMD ["python", "run.py"]