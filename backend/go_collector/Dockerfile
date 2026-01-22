FROM golang:1.24 AS builder

WORKDIR /app

# Sistem bağımlılıkları
RUN apt-get update && apt-get install -y git

# Go mod dosyalarını kopyala
COPY go.mod go.sum ./
RUN go mod download

# Kaynak kodları kopyala
COPY . .

# Binary'yi derle (CGO gerekli chromedp için)
RUN go build -o collector .

# Final image - Chromium için Debian tabanlı
FROM debian:bookworm-slim

# Chromium ve gerekli bağımlılıkları yükle
RUN apt-get update && apt-get install -y \
    chromium \
    chromium-sandbox \
    ca-certificates \
    tzdata \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /root/

# Binary'yi kopyala
COPY --from=builder /app/collector .

# Output dizini oluştur
RUN mkdir -p /root/output

# Çalıştır
CMD ["./collector"]

