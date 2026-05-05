# Kubit CDN

Простая CDN-нода на Docker: nginx proxy cache + WEB-админка в стиле ikubit.ru.

## Что умеет

- WEB-настройка origin-сервера.
- Включение/выключение CDN-ноды.
- TTL для успешных ответов и 404.
- Кеширование через `nginx proxy_cache`.
- `X-Cache-Status: MISS/HIT/BYPASS` в ответах.
- Очистка кеша из админки.
- CORS.
- Защита от hotlink по Referer.
- Дополнительные HTTP-заголовки.
- SQLite без внешней БД.

## Установка на чистый VDS

```bash
mkdir -p /docker
cd /docker
git clone https://github.com/AlexanderGalchenko/kubit_cdm.git cdn
cd /docker/cdn
chmod +x install.sh
./install.sh
```

Либо вручную:

```bash
cd /docker/cdn
docker compose up -d --build
```

Админка:

```text
http://SERVER_IP/admin/
```

Логин и пароль задаются в `docker-compose.yml`:

```yaml
environment:
  ADMIN_USER: admin
  ADMIN_PASSWORD: change-me-now
```

## Проверка кеша

```bash
curl -I http://SERVER_IP/path/to/file.jpg
curl -I http://SERVER_IP/path/to/file.jpg
```

Первый запрос обычно `MISS`, второй — `HIT`.

## Важные директории

```text
/docker/cdn/data   — SQLite и nginx generated config
/docker/cdn/cache  — кеш контента
/docker/cdn/logs   — access/error logs nginx
```

## DNS для клиента

Клиент заводит CNAME или A-запись на CDN-ноду, например:

```text
cdn.client.ru A SERVER_IP
```

В админке origin указывается, например:

```text
https://client.ru
```

Если origin требует конкретный Host, укажи `Host header`.

## Ограничения MVP

Это стартовая одиночная CDN-нода, не полноценная CDN-сеть. Здесь нет:

- Anycast/GeoDNS.
- биллинга клиентов;
- мультидоменных сертификатов Let’s Encrypt;
- API управления нодами;
- квот по трафику;
- purge по URL/маске, только полная очистка кеша.

Для продажи клиентам следующим этапом нужно добавить multi-tenant: домены, отдельные origin, SSL на каждый домен, API и центральную панель управления.
